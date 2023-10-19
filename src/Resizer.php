<?php

namespace Tomkirsch\Resizer;

/*
	Automagically resize a source image with caching
	If .htaccess doesn't seem to want to recognize the path, try setting CI's App config $uriProtocol to PATH_INFO
*/

class Resizer
{
	public $config;
	public $imageLib;

	public function __construct($config)
	{
		$this->config = $config;
	}

	// reads a certain size image and outputs contents. If the source is newer than the cache, it is automatically cleaned
	public function read(string $imageFile, int $size, string $ext = '.jpg')
	{
		// random clean
		if ($this->config->useCache && rand(1, $this->config->randomCleanChance) === 1) {
			$this->cleanDir(FALSE);
		}
		// load GD image lib
		if (!$this->imageLib) $this->imageLib = \Config\Services::image('gd');
		$sourceFile = $this->sourceFile($imageFile, $ext);
		$cacheFile = $this->cacheFile($imageFile, $size, $ext);
		$sourceTime = filemtime($sourceFile);

		// find an alternate cache if the correct size is not available
		$altCache = NULL;
		if (!file_exists($cacheFile)) {
			$cacheMap = [];
			foreach (glob($this->config->resizerCachePath . '/' . $imageFile . "*") as $altCache) {
				// remove path, file name, and ext
				$width = preg_replace('/^.*-([0-9]+)\..*$/', '$1', $altCache);
				if (!is_numeric($width)) continue;
				$width = intval($width);
				if ($width < $size) continue;
				$cacheMap[intval($width)] = $altCache;
			}
			// sort by width
			ksort($cacheMap);
			if (count($cacheMap)) $altCache = array_shift($cacheMap);
			// ensure alt cache filemtime is older than source
			if ($altCache && filemtime($altCache) > $sourceTime) {
				try {
					unlink($altCache);
				} catch (\Exception $e) {
					log_message('error', 'Resizer Lib cannot unlink file ' . $altCache);
				}
				$altCache = NULL;
			}
		}

		if (!file_exists($cacheFile)) {
			$createCache = $this->config->useCache;
			$cacheExists = FALSE;
		} else if ($sourceTime > filemtime($cacheFile)) {
			// source was updated, clear cache
			try {
				unlink($cacheFile);
			} catch (\Exception $e) {
				log_message('error', 'Resizer Lib cannot unlink file ' . $cacheFile);
			}
			$createCache = $this->config->useCache;
			$cacheExists = FALSE;
		} else {
			// cache is valid
			$createCache = FALSE;
			$cacheExists = TRUE;
		}

		// resize the file
		if (!$cacheExists) {
			$this->imageLib
				->withFile($altCache ?? $sourceFile)
				->resize($size, $size, TRUE, 'width');
			if ($createCache) {
				$this->imageLib->save($cacheFile);
				$cacheExists = TRUE;
			}
		}

		// remove any CI output buffering
		ob_end_flush();

		// generate headers
		$extNoDot = substr($ext, 1);
		$mime = $this->mimeFromExt($extNoDot);
		if ($mime) header("Content-Type: $mime");
		$headerDate = gmdate('D, d M Y H:i:s T', $sourceTime);
		header("Last-Modified: $headerDate");
		header("Cache-control: public, max-age=2592000"); // 1 mo.
		// if we have a cache file, read it
		if ($cacheExists) {
			header("Content-Length: " . filesize($cacheFile));
			readfile($cacheFile);
		} else {
			// image resource should still exist, output it to the browser using GD function
			$this->outputResource($extNoDot);
		}
	}

	// cleans the entire cache dir. Uses filemtime or forced deletion
	public function cleanDir(bool $forceCleanAll = FALSE): array
	{
		// interator
		$dir = new \RecursiveDirectoryIterator($this->config->resizerCachePath);
		$filter = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) {
			// Skip hidden files and directories.
			if ($current->getFilename()[0] === '.') return FALSE;
			if ($current->isDir()) return TRUE; // recursive
			return TRUE;
		});
		$iterator = new \RecursiveIteratorIterator($filter);
		$files = [];
		foreach ($iterator as $info) {
			// delete file if $forceCleanAll, or file is older than our ttl
			$expired = $this->config->ttl ? $info->getMTime() + $this->config->ttl > time() : FALSE;
			if ($forceCleanAll || $expired) {
				$file = $info->getRealPath();
				try {
					unlink($file);
					$files[] = $file;
				} catch (\Exception $e) {
					log_message('error', 'Resizer Lib cannot unlink file ' . $file);
				}
			}
		}
		return $files;
	}

	// utility - removes all cached files for a given image
	public function cleanFile(string $imageFile): array
	{
		$dir = new \RecursiveDirectoryIterator($this->config->resizerCachePath);
		$filter = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) use ($imageFile) {
			// Skip hidden files and directories.
			if ($current->getFilename()[0] === '.') return FALSE;
			if ($current->isDir()) return TRUE; // recursive
			// only delete files with the given filename
			return stristr($current->getFilename(), $imageFile);
		});
		$files = [];
		$iterator = new \RecursiveIteratorIterator($filter);
		foreach ($iterator as $info) {
			$file = $info->getRealPath();
			try {
				unlink($file);
				$files[] = $file;
			} catch (\Exception $e) {
				log_message('error', 'Resizer Lib cannot unlink file ' . $file);
			}
		}
		return $files;
	}

	// utility - generate a public-pointing file path
	public function publicFile(string $imageFile, int $size, string $ext = '.jpg'): string
	{
		return $this->config->rewriteSegment . '/' . $imageFile . $this->config->rewriteSizeSep . $size . $ext;
	}

	// utility - gets the path + name of the source image file
	public function sourceFile(string $imageFile, string $ext = '.jpg'): string
	{
		return $this->config->realImagePath . '/' . $imageFile . $ext;
	}

	// utility - gets the path + name of the cache file, and ensures the path exists
	public function cacheFile(string $imageFile, int $size, string $ext = '.jpg'): string
	{
		// ensure source file exists before we create directories!
		$sourceFile = $this->sourceFile($imageFile, $ext);
		if (!is_file($sourceFile)) {
			throw new \Exception("Cannot find source file $sourceFile");
		}
		$cacheDir = $this->config->resizerCachePath;
		$cacheFileName = $imageFile . '-' . $size . $ext;
		$cacheFile = "$cacheDir/$cacheFileName";
		$pathinfo = pathinfo($cacheFile);
		if (!is_dir($pathinfo['dirname'])) {
			mkdir($pathinfo['dirname'], 0777, TRUE);
		}
		return $cacheFile;
	}

	protected function mimeFromExt(string $ext): ?string
	{
		$mime = NULL;
		switch (strtolower($ext)) {
			case 'jpg':
			case 'jpeg':
				$mime = image_type_to_mime_type(IMAGETYPE_JPEG);
				break;
			case 'png':
				$mime = image_type_to_mime_type(IMAGETYPE_PNG);
				break;
			case 'webp':
				$mime = image_type_to_mime_type(IMAGETYPE_WEBP);
				break;
			case 'gif':
				$mime = image_type_to_mime_type(IMAGETYPE_GIF);
				break;
		}
		return $mime;
	}

	protected function outputResource(string $ext)
	{
		switch (strtolower($ext)) {
			case 'jpg':
			case 'jpeg':
				$func = 'imagejpeg';
				break;
			case 'png':
				$func = 'imagepng';
				break;
			case 'webp':
				$func = 'imagewebp';
				break;
			case 'gif':
				$func = 'imagegif';
				break;
		}
		$func($this->imageLib->getResource());
	}
}
