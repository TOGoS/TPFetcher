<?php

class TOGoS_Fetcher_FetchFailure extends Exception
{
	public $messages;
	
	public function __construct(array $messages) {
		parent::__construct(implode("\n",$messages));
		$this->messages = $messages;
	}
}

class TOGoS_Fetcher_HashUtil
{
	/**
	 * Extract an SHA1 hash from a sha1/bitprint URN, hex-encoded
	 * string, or non-encoded string (i.e. the has itself)
	 */
	public static function extractSha1( $string ) {
		if( preg_match( '/^(?:(?:urn:)?(?:sha1|bitprint):)?([0-9A-Z]{32})(?:$|\W)/i', $string, $bif ) ) {
			return TOGoS_Base32::decode($bif[1]);
		} else if( preg_match('/^[0-9a-f]{40}$/i', $string) ) {
			return hex2bin($string);
		} else if( strlen($string) == 20 ) {
			return $string;
		} else {
			throw new Exception("Unable to extract SHA-1 from string '$string'");
		}
	}
	
	public static function extractBase32Sha1( $string ) {
		if( preg_match( '/^(?:(?:urn:)?(?:sha1|bitprint):)?([0-9A-Z]{32})(?:$|\W)/i', $string, $bif ) ) {
			return $bif[1];
		} else {
			return TOGoS_Base32::encode(self::extractSha1($string));
		}
	}
}

class TOGoS_Fetcher {
	public static function defuzzRemoteRepoPrefix( $url ) {
		// Bare hostname?
		if( preg_match('#^[^/]+$#', $url) ) {
			$url = "http://$url";
		}
		// No path?
		if( preg_match('#^https?://[^/]+$#', $url) ) {
			$url .= '/uri-res/N2R?';
		}
		// Ends with something other than '/' or '?'?
		if( !preg_match('#[\?/]$#', $url) ) {
			$url .= '?';
		}
		return $url;
	}
	
	protected static function joinPath( $p1, $p2 ) {
		if( $p1[strlen($p1)-1] == '/' ) $p1 = substr($p1,0,strlen($p1)-1);
		if( $p2[0] == '/' ) $p2 = substr($p2,1);
		return "{$p1}/{$p2}";
	}
	
	protected static function findStandardRemoteRepoListsIn( $dir, array &$files ) {
		$searchSuffixes = array(
			'.ccouch/remote-repos.lst',
			'.ccouch-remote-repos.lst'
		);
		foreach( $searchSuffixes as $s ) {
			if( file_exists($lf = self::joinPath($dir,$s)) ) {
				$files[$lf] = $lf;
			}
		}
	}
	
	public static function findStandardRemoteRepoLists( array &$files ) {
		$dir = getcwd();
		while( !empty($dir) ) {
			self::findStandardRemoteRepoListsIn($dir, $files);
			$pDir = dirname($dir);
			if( $pDir == $dir ) break;
			$dir = $pDir;
		}
		if( isset($_SERVER['HOME']) ) {
			self::findStandardRemoteRepoListsIn($_SERVER['HOME'], $files);
		}
		//$nuke = 'http://www.nuke24.net/ccouch-remote-repos.lst';
		//$files[$nuke] = $nuke;
	}
	
	public static function loadStandardRepoLists( array &$remoteRepoUrls=array() ) {
		$listFiles = array();
		self::findStandardRemoteRepoLists( $listFiles );
		foreach( $listFiles as $lf ) {
			self::loadRepoList($lf, $remoteRepoUrls);
		}
		return $remoteRepoUrls;
	}
	
	public static function loadRepoList( $file, array &$remoteRepoUrls=array() ) {
		$fh = @fopen($file, 'r');
		if( $fh === false ) {
			fwrite(STDERR, "Warning: Couldn't open repository list file '$file'\n");
			return;
		}
		while( ($line = fgets($fh)) !== false ) {
			$line = trim($line);
			if( $line == '' or $line[0] == '#' ) continue;
			if( preg_match('/^(\S+) = (\S+)$/',$line,$bif) ) {
				$fuzz = $bif[2];
			} else {
				$fuzz = $line;
			}
			$url = TOGoS_Fetcher::defuzzRemoteRepoPrefix($fuzz);
			$remoteRepoUrls[$url] = $url;
		}
		fclose($fh);
		return $remoteRepoUrls;
	}
	
	protected $cacheRepoDir;
	protected $cacheSector;
	protected $remoteRepoUrls;
	public $downloadAttemptCount = 0;
	public $downloadCount = 0;
	
	public function __construct( $cacheRepoDir, $cacheSector, array $remoteRepoUrls ) {
		$this->cacheRepoDir = $cacheRepoDir;
		$this->cacheSector = $cacheSector;
		$this->remoteRepoUrls = $remoteRepoUrls;
	}
	
	protected function possibleRemoteUrls( $urn ) {
		$r = array();
		foreach( $this->remoteRepoUrls as $u ) {
			$r[] = $u.$urn;
		}
		shuffle($r);
		return $r;
	}
	
	protected function mkDirs( $dir ) {
		if( !is_dir($dir) ) mkdir($dir, 0755, true);
	}
	
	protected function mkParentDirs( $file ) {
		$this->mkDirs( dirname($file) );
	}
	
	protected function completeDownload( $tempFile, $destFile, $expectedSize ) {
		if( !rename($tempFile, $destFile) ) {
			throw new Exception("Failed to move '$tempFile' to '$destFile'");
		}
		if( file_exists($destFile) and ($destSize = filesize($destFile)) != $expectedSize ) {
			unlink($destFile);
			throw new Exception("Downloaded $size bytes but '$destFile' is only $destSize.");
		}
		chmod($destFile, 0644);
		return $destFile;
	}
	
	/**
	 * Returns the path of the file, if successfully cached
	 */
	public function cache( $urn ) {
		$base32Sha1 = TOGoS_Fetcher_HashUtil::extractBase32Sha1($urn);
		$psp = substr($base32Sha1,0,2)."/".$base32Sha1;
		$dataDir = "{$this->cacheRepoDir}/data";
		if( is_dir($dataDir) ) {
			foreach( scandir($dataDir) as $sector ) {
				if( $sector[0] == '.' ) continue;
				$existing = "{$dataDir}/{$sector}/{$psp}";
				if( file_exists($existing) ) return $existing;
			}
		}
		
		$destFile = "{$dataDir}/{$this->cacheSector}/{$psp}";
		$tempFile = $this->tempFile($destFile);
		try {
			$size = $this->download( $urn, $tempFile );
		} catch( Exception $e ) {
			unlink($tempFile);
			throw $e;
		}
		
		return $this->completeDownload( $tempFile, $destFile, $size );
	}
	
	protected function tempFile( $f ) {
		$dir = dirname($f) ?: '.';
		return tempnam($dir, ".temp-".basename($f));
	}

	/**
	 * @return number of bytes downloaded and written to $destFile
	 */
	protected function download( $urn, $destFile ) {
		$expectedSha1 = TOGoS_Fetcher_HashUtil::extractSha1($urn);
		$failures = array();
		
		foreach( $this->possibleRemoteUrls($urn) as $url ) {
			++$this->downloadAttemptCount;
			$rfh = @fopen($url, 'rb');
			if( $rfh === false ) {
				$failures[] = "$url: failed to open stream";
				continue;
			}

			$this->mkParentDirs($destFile);
			$wfh = fopen($destFile, 'wb');
			// TODO: identify hash functions used in URN and try to verify all possible
			if( $wfh === false ) {
				throw new Exception("Failed to open '$destFile' for writing.");
			}
			
			$size = 0;
			$startedWriting = true;
			$hasher = hash_init('sha1');
			while( $buf = fread($rfh, 1024*1024) ) {
				fwrite($wfh, $buf);
				hash_update($hasher, $buf);
				$size += strlen($buf);
			}
			fclose($wfh);
			fclose($rfh);
			$sha1 = hash_final($hasher, true);
			if( $sha1 == $expectedSha1 ) {
				++$this->downloadCount;
				return $size;
			}
			
			$failures[] = "$url: expected SHA-1 ".TOGoS_Base32::encode($expectedSha1).
				", got ".TOGoS_Base32::encode($sha1);
		}
		
		if( count($this->remoteRepoUrls) == 0 ) {
			$failures[] = "No remote repositories were given.";
		}
		
		throw new TOGoS_Fetcher_FetchFailure($failures);
	}
	
	public function checkout( $urn, $destFile ) {
		// TODO: Check cache!
		// TODO: Check if the file is already checked out!
		$tempFile = $this->tempFile($destFile);
		try {
			$size = $this->download( $urn, $tempFile );
		} catch( Exception $e ) {
			unlink($tempFile);
			throw $e;
		}
		return $this->completeDownload( $tempFile, $destFile, $size );
	}
}
