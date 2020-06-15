<?php
/**
 * Miniskewl - CSS/JS aggregator, minifier and compressor plugin for Joomla!
 * Copyright (c)2010 Nicholas K. Dionysopoulos / dionysopoulos.me
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

defined('_JEXEC') or die();

jimport( 'joomla.plugin.plugin' );

class  plgSystemMiniskewl extends JPlugin
{
	/* When set to true, the cache files will be regenerated every time */
	const DEBUG = false;

	/** @var array A list of CSS files */
	private $cssFiles;

	/** @var array A list of JS files */
	private $jsFiles = array();

	/** @var string The base URL of this site */
	private $baseURL = array();

	/** @var string Absolute path to the cache directory */
	private $cacheFolder = '';

	/** @var string The cache hash used for JS files */
	private $jsHash = '';

	/** @var string The cache hash used for CSS files */
	private $cssHash = '';

	function plgSystemMiniskewl(& $subject, $config)
	{
		parent::__construct($subject, $config);

		// Load the translation
		$this->loadLanguage( );

		// Make sure the cache directory exists
		jimport('joomla.filesystem.folder');
		$this->cacheFolder = JPATH_ROOT.DS.'cache'.DS.'miniskewl';
		if(!JFolder::exists($this->cacheFolder)) {
			JFolder::create($this->cacheFolder);
		}

		// Do we have to output a file?
		$hash = JRequest::getCmd('miniskewl',null);
		if(!empty($hash))
		{
			$this->deliverFile($hash);
		}
	}

	function onAfterRender()
	{
		// Run only on site and admin applications
		$app =& JFactory::getApplication();
		if( !in_array($app->getName(), array('site'))  ) {
			return true;
		}

		// Only parse HTML output
		if ( JFactory::getDocument()->getType() != 'html' ) return true;

		// Get the site's base URI
		$base = JURI::base();
		if( $app->getName() == 'administrator' ) {
			$base = rtrim($base,'/');
			$base_pieces = @explode('/',$base);
			array_pop($base_pieces);
			$base = @implode('/', $base_pieces).'/';
		}
		$this->baseURL = $base;

		// Get the HTML content from the JResponse class
		$body = JResponse::getBody();

		// Load Joomla! classes
		jimport('joomla.filesystem.file');

		// JavaScript handling
		// =====================================================================

	 	// Parse JavaScript separators
		$this->jsFiles = array();
		$scriptRegex="/<script [^>]+(\/>|><\/script>)/i";
	 	$jsRegex="/([^\"\'=]+\.(js)(\?[^\"\']*){0,1})[\"\']/i";
	 	preg_match_all($scriptRegex, $body, $matches);
	 	$scripts=@implode('',$matches[0]);
	 	preg_match_all($jsRegex,$scripts,$matches);
	 	foreach( $matches[1] as $url )
		{
			$file = $url; // Clone the string, as it gets modified
			if($this->isInternal($file))
			{
				// Separate any URL query
				$qmPos = strpos($file,'?');
				if( $qmPos !== false )
				{
					// Strip the query
					$query = substr($file, $qmPos+1);
					$file = substr($file, 0, $qmPos);
				}
				else
				{
					$query = '';
				}

				// Do not add dynamicaly generated files (.php)
				if( substr( strtolower($file), -4 ) == '.php' ) continue;

				// Make sure the file exists
				if( !JFile::exists(JPATH_ROOT.DS.$file) ) {
					if($app->getName() == 'administrator') {
						if( !JFile::exists(JPATH_ROOT.DS.'administrator'.DS.$file) ) {
							continue;
						} else {
							$file = 'administrator'.DS.$file;
						}
					} else continue;
				}

				// Make sure the file is readable
				if( !is_readable(JPATH_ROOT.DS.$file) ) continue;

				// Try to get the date and file size
				$date = @filectime($file);
				$size = @filesize($file);

				if((int)$size == 0) $size = PHP_INT_MAX;

				// Calculate a unique file hash
				$hash = md5($file.$query.$date.$size);

				$this->jsFiles[] = (object)array(
					'url'		=> $url,
					'file'		=> $file,
					'hash'		=> $hash,
					'replace'	=> true,
					'size'		=> $size
				);
			}
		}

		// Create unique hash of all JS files
		if(count($this->jsFiles))
		{
			$hashable = '';
			foreach($this->jsFiles as $js)
			{
				$hashable .= $js->hash;
			}
			$jsHash = md5($hashable);
		}

		// Does the cache file exist?
		if(!JFile::exists($this->cacheFolder.DS.'js-'.$jsHash.'.php') || self::DEBUG)
		{
			// Nope. Let's create the file.
			$hashable = '';
			$jsContent = "<?php die(); ?>\n";
			foreach($this->jsFiles as $file)
			{
				$myContent = JFile::read(JPATH_ROOT.DS.$file->file, false, $file->size);
				if($myContent === false) {
					$file->replace = false;
					continue;
				}
				$basename = basename($file->file);
				$jsContent .= $myContent."\n";
				$hashable .= $file->hash;
			}
			// We recalculate the cache, in case we had unreadable files
			$jsHash = md5($hashable);
			// Write the cache file
			JFile::write($this->cacheFolder.DS.'js-'.$jsHash.'.php', $jsContent);
		}

		// Set the cache hash for JS files
		$this->jsHash = $jsHash;

		// Replace the JS files
		$body=preg_replace_callback($scriptRegex,array($this,'replaceJS'),$body);
		$newHeadCode='</title>
<script type="text/javascript" src="'.$this->baseURL.'?miniskewl=js-'.$this->jsHash.'"></script>';
		//only match once
		$body = preg_replace('/<\/title>/i',$newHeadCode , $body,1);
		JResponse::setBody($body);

		// CSS handling
		// =====================================================================

		// Banned files from being minified
		$cssBanList = array(
		);

	 	// Parse CSS separators
		$this->cssFiles = array();
		$conditionRegex="/<\!--\[if.*?\[endif\]-->/is";
	 	$linksRegex="|<link[^>]+[/]?>((.*)</[^>]+>)?|U";
		$cssRegex="/([^\"\'=]+\.(css)(\?[^\"\']*){0,1})[\"\']/i";

		// Parse conditional CSS files
		preg_match_all($conditionRegex,$body,$conditonMatches);
		if(!empty($conditonMatches)){
	 		preg_match_all($linksRegex,@implode('',$conditonMatches[0]),$conditionCss);
	 		if(!empty($conditionCss[0])){
	 			preg_match_all($cssRegex,@implode('',$conditionCss[0]),$conditionCssFiles);
	 			if(!empty($conditionCssFiles[1])){
	 				foreach($conditionCssFiles[1] as $conditionalCss){
	 					$url = trim($conditionalCss);
						$isInternal = $this->isInternal($url);
						if($isInternal)
						{
							$cssBanList[]=$url;
						}
	 				}
	 			}
	 		}
	 	}

		preg_match_all($linksRegex, $body, $matches);
		$links=@implode('',$matches[0]);
		preg_match_all($cssRegex,$links,$matches);

	 	foreach( $matches[1] as $url )
		{
			$file = $url; // Clone the string, as it gets modified
			if($this->isInternal($file))
			{
				// Separate any URL query
				$qmPos = strpos($file,'?');
				if( $qmPos !== false )
				{
					// Strip the query
					$query = substr($file, $qmPos+1);
					$file = substr($file, 0, $qmPos);
				}
				else
				{
					$query = '';
				}

				// Do not add dynamicaly generated files (.php)
				if( substr( strtolower($file), -4 ) == '.php' ) continue;

				// Make sure the file exists
				if( !JFile::exists(JPATH_ROOT.DS.$file) ) {
					if($app->getName() == 'administrator') {
						if( !JFile::exists(JPATH_ROOT.DS.'administrator'.DS.$file) ) {
							continue;
						} else {
							$file = 'administrator'.DS.$file;
						}
					} else continue;
				}

				// Make sure the file is readable
				if( !is_readable(JPATH_ROOT.DS.$file) ) continue;

				// Try to get the date and file size
				$date = @filectime($file);
				$size = @filesize($file);

				// Calculate a unique file hash
				$hash = md5($file.$query.$date.$size);

				// Should I minify the file?
				$replace = true;
				if( in_array($file, $cssBanList) ) {
					$replace = false;
				}

				$this->cssFiles[] = (object)array(
					'url'		=> $url,
					'file'		=> $file,
					'hash'		=> $hash,
					'replace'	=> $replace,
					'minify'	=> true
				);
			}
		}

		// Create unique hash of all CSS files
		if(count($this->cssFiles))
		{
			$hashable = '';
			foreach($this->cssFiles as $css)
			{
				if(!$css->replace) continue;
				$hashable .= $css->hash;
			}
			$cssHash = md5($hashable);
		}

		// Does the cache file exist?
		if(!JFile::exists($this->cacheFolder.DS.'css-'.$cssHash.'.php') || self::DEBUG)
		{
			// Nope. Let's create the file.
			require_once dirname(__FILE__).DS.'miniskewl'.DS.'cssmin.php';
			$hashable = '';
			$cssContent = "<?php die(); ?>\n";
			foreach($this->cssFiles as $file)
			{
				if(!$file->replace) continue;

				$myContent = JFile::read(JPATH_ROOT.DS.$file->file);
				if($myContent === false) {
					$file->replace = false;
					continue;
				}
				cssmin::$baseURL = $this->baseURL . trim(dirname($file->file),'/\\') ;
				if(DS == '\\') cssmin::$baseURL = str_replace (DS, '/', cssmin::$baseURL);
				$cssContent .= cssmin::minify($myContent,'remove-last-semicolon,preserve-urls')."\n";
				$hashable .= $file->hash;
			}
			// Post process @import directives
			$cssRegex="/([^\"\'=]+\.(css)(\?[^\"\']*){0,1})[\"\']/i";
			$importRegex = '/@import[ ]*([^;]+);/i';
			preg_match_all($importRegex, $cssContent, $mat);
			$importScript = '';
			if(!empty($mat))
			{
				$counter = 0;
				foreach($mat[0] as $removeit)
				{
					$urlpart = $mat[1][$counter];
					$urlpart = trim($urlpart,'"\' ');
					if(substr($urlpart,0,3) == 'url') $urlpart = trim(substr($urlpart,3));
					$urlpart = ltrim(rtrim($urlpart,')'),'(');
					$urlpart = trim($urlpart);
					$urlpart = trim($urlpart,'"');

					$url = $urlpart;
					$isInternal = $this->isInternal($url);
					if($isInternal)
					{
						$replace = true;
						$newData = JFile::read(JPATH_ROOT.DS.$url);
						if($newData === false) $replace = false;

						if($replace) {
							if(strstr($newData,'@import')) $replace = false;
						}

						if($replace) {
							cssmin::$baseURL = $this->baseURL . trim(dirname($url),'/\\') ;
							if(DS == '\\') cssmin::$baseURL = str_replace (DS, '/', cssmin::$baseURL);
							$replaceWith = cssmin::minify($newData,'remove-last-semicolon,preserve-urls')."\n";
						} else {
							$importScript .= '@import url("'.$urlpart.'");'."\n";
							$replaceWith = '';
						}
					}
					else
					{
						$importScript .= '@import url("'.$urlpart.'");'."\n";
						$replaceWith = '';
					}

					$cssContent = str_replace($removeit, $replaceWith, $cssContent);
					$counter++;
				}
			}

			// We recalculate the cache, in case we had unreadable files
			$cssHash = md5($hashable);
			// Write the cache file
			JFile::write($this->cacheFolder.DS.'css-'.$cssHash.'.php', $cssContent);
		}

		// Set the cache hash for JS files
		$this->cssHash = $cssHash;

		// Replace the CSS files
		$body=preg_replace_callback($linksRegex,array($this,'replaceCSS'),$body);
		$newHeadCode='</title>
<link rel="stylesheet" type="text/css" href="'.$this->baseURL.'?miniskewl=css-'.$this->cssHash.'" />';
		//only match once
		$body = preg_replace('/<\/title>/i',$newHeadCode , $body,1);
		JResponse::setBody($body);

		// Debug information (if necessary)
		// =====================================================================
		if(JDEBUG) $this->debug();
	}

	/**
	 * Callback method to remove the JavaScript <script> tags
	 * @param array $matches
	 * @return string
	 */
	public function replaceJS($matches)
	{
		$jsRegex="/src=[\"\']([^\"\']+)[\"\']/i";
		preg_match_all($jsRegex, $matches[0], $m);
		if(isset($m[1])&&count($m[1])){
			// Get the URL of the script
			$url=$m[1][0];
			// Sanitize it
			$filename = $url;
			$junk = $this->isInternal($filename);
			$qmPos = strpos($filename,'?');
			if( $qmPos !== false ) {
				// Strip the query
				$query = substr($filename, $qmPos+1);
				$file = substr($filename, 0, $qmPos);
			} else {
				$query = '';
			}

			// Check if it marked as "do not replace"
			if(count($this->jsFiles))
			{
				$found = false;
				foreach($this->jsFiles as $file)
				{
					if(($file->url == $url)) $found = true;
					if(($file->file == $filename)) $found = true;
					if(($file->file == 'administrator'.DS.$filename)) $found = true;
					if( $found && !$file->replace )
					{
						$found = false;
						return $matches[0];
					}
					if($found) {
						$file->REPLACED = 'REPLACED';
						return ' ';
					}
				}
				if(!$found) return $matches[0];
			}
			else
			{
				return $matches[0];
			}
			// If we are still here, the script must be removed, so we'll just
			// replace it with an empty string!
			return ' ';
		}
		else
		{
			return $matches[0];
		}
	}

	/**
	 * Callback method to remove the CSS <link> tags
	 * @param array $matches
	 * @return string
	 */
	public function replaceCSS($matches)
	{
		$cssRegex="/([^\"\'=]+\.(css)(\?[^\"\']*){0,1})[\"\']/i";
		preg_match_all($cssRegex, $matches[0], $m);
		if(isset($m[1])&&count($m[1])){
			// Get the URL of the script
			$url=$m[1][0];
			// Sanitize it
			$filename = $url;
			$junk = $this->isInternal($filename);
			$qmPos = strpos($filename,'?');
			if( $qmPos !== false ) {
				// Strip the query
				$query = substr($filename, $qmPos+1);
				$file = substr($filename, 0, $qmPos);
			} else {
				$query = '';
			}

			// Check if it marked as "do not replace"
			if(count($this->cssFiles))
			{
				$found = false;
				foreach($this->cssFiles as $file)
				{
					if(($file->url == $url)) $found = true;
					if(($file->file == $filename)) $found = true;
					if(($file->file == 'administrator'.DS.$filename)) $found = true;
					if( $found && !$file->replace )
					{
						$found = false;
						return $matches[0];
					}
					if($found) {
						$file->REPLACED = 'REPLACED';
						return ' ';
					}
				}
				if(!$found) return $matches[0];
			}
			else
			{
				return $matches[0];
			}
			// If we are still here, the script must be removed, so we'll just
			// replace it with an empty string!
			return ' ';
		}
		else
		{
			return $matches[0];
		}
	}

	/**
	 * Delivers a cache file to the browser
	 * @param string $hash
	 */
	private function deliverFile($hash)
	{
		// Load Joomla! libraries
		jimport('joomla.filesystem.file');
		jimport('joomla.utilities.date');

		// Kill caching
		ob_end_clean();

		// Check that it's a js- or css- file, or throw a Forbidden message
		$pass = (substr($hash,0,3) == 'js-') || (substr($hash,0,4) == 'css-');

		// Is the file there?
		if($pass) $pass = $pass && JFile::exists($this->cacheFolder.DS.$hash.'.php');

		// Can we read the file?
		if($pass)
		{
			$content = JFile::read($this->cacheFolder.DS.$hash.'.php');
			if($content === false) {
				// Can't read the file, no go.
				$pass = false;
			} else {
				// Get rid of the first line (the die() PHP statement)
				$firstNewline = strpos($content,"\n");
				$content = substr($content,$firstNewline);
			}
		}

		// If there is something wrong, throw a Forbidden header
		if(!$pass) {
			if(!headers_sent()) header('HTTP/1.0 403 Forbidden');
			die();
		}

		// Guess the appropriate content type
		$contentType = (substr($hash,0,3) == 'js-') ? 'text/javascript' : 'text/css';
		$suffix = (substr($hash,0,3) == 'js-') ? 'js' : 'css';

		// Calculate the expiration date
		$filedate = @filectime($this->cacheFolder.DS.$hash.'php');
		if($filedate === false) $filedate = time();
		$date = new JDate($filedate,0);
		$modified = $date->toRFC822();
		$filedate += 31536000; // Add one year
		$date = new JDate($filedate,0);
		$expires = $date->toRFC822();

		// Calculate data length
		$length = strlen($content);

		// Check if the browser tries to validate against an ETag
		if(function_exists('getallheaders'))
		{
			$headers = getallheaders();
			foreach($headers as $key => $value)
			{
				if(strtolower($key) == 'if-none-match') {
					if(strstr($value, $hash)) {
						if(!headers_sent()) {
							header('HTTP/1.1 304 Not Modified');
						}
					}
				}
			}
		}

		// Send our headers
		if(!headers_sent())
		{
			header("ETag: \"{$hash}\"");
			header("Expires: $expires");
			header("Last-Modified: $modified");
			header("Content-type: $contentType");
			header("Content-Disposition: inline; filename=\"$hash.$suffix\";");
		}

		// Do we have to compress?
		$app =& JFactory::getApplication();
		$compress = $app->getCfg('gzip');

		if($compress) {
			// Get the client supported encoding
			$encoding = false;
			if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
				if (false !== strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
					$encoding = 'gzip';
				}
				if (false !== strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'x-gzip')) {
					$encoding = 'x-gzip';
				}
			}
		}

		if($compress && !ini_get('zlib.output_compression') && ini_get('output_handler')!='ob_gzhandler' && !headers_sent() && extension_loaded('zlib') && (connection_status() === 0) && $encoding)
		{
			$level = 4; //ideal level
			$gzdata = gzencode($content, $level);
			header("Content-Encoding: $encoding");
			header("Content-Length: ".strlen($gzdata));
			echo $gzdata;
		}
		else
		{
			header("Content-Length: $length");
			echo $content;
		}
		die();
	}

	/**
	 * Figures out if a URL is internal (comes from this site) or if it is an
	 * external URL, while figuring out the file it refers to as well.
	 * @param string $url Input: the URL; Output: the file for this URL
	 * @return bool
	 */
	private function isInternal(&$url)
	{
		if( (strtolower(substr($url,0,7)) == 'http://') ||
			(strtolower(substr($url,0,8)) == 'https://') )
		{
			// Strip the protocol from the URL
			if((strtolower(substr($url,0,7)) == 'http://')) {
				$url = substr($url,7);
			} else {
				$url = substr($url, 8);
			}
			// Strip the protocol from our own site's URL
			if((strtolower(substr($this->baseURL,0,7)) == 'http://')) {
				$base = substr($this->baseURL,7);
			} else {
				$base = substr($this->baseURL, 8);
			}
			// Does the domain match?
			if(strtolower(substr($url,0,strlen($base))) == strtolower($base) )
			{
				// Yes, trim the url
				$url = ltrim(substr($url,strlen($base)),'/\\');
				return true;
			}
			else
			{
				// Nope, it's an external URL
				return false;
			}

		}
		else
		{
			// No protocol, ergo we are a relative internal URL

			$app =& JFactory::getApplication();
			if( (substr($url,0,1) != '/') && ($app->getName() == 'admin') )
			{
				// Relative URL to the administrator directory
				$url = 'administrator/'.$url;
			}

			$url = ltrim($url,'/\\');
			return true;
		}

	}

	private function debug()
	{
		ob_start();
		echo '<div id="system-debug" class="profiler">';
		echo '<h4>Miniskewl information</h4>';

		echo '<h5>Javascript files</h5><pre>';
		var_dump($this->jsFiles);
		echo '</pre>';

		echo '<h5>CSS files</h5><pre>';
		var_dump($this->cssFiles);
		echo '</pre>';

		echo '<h5>Hashes</h5><pre>';
		echo "Javascript files: {$this->jsHash}\n";
		echo "CSS files: {$this->cssHash}\n";
		echo '</pre>';

		echo '</div>';
		$debug = ob_get_clean();

		$body = JResponse::getBody();
		$body = str_replace('</body>', $debug.'</body>', $body);
		JResponse::setBody($body);
	}

}