<?php

namespace Tomkirsch\Resizer;

use CodeIgniter\Images\Handlers\BaseHandler;

/**
 * Automagically resize a source image with caching using CI's GD image library.
 * If .htaccess doesn't seem to want to recognize the path, try setting CI's App config $uriProtocol to PATH_INFO
 **/

class Resizer
{
	public ?ResizerConfig $config = NULL;
	public ?BaseHandler $imageLib = NULL;

	public function __construct(ResizerConfig $config)
	{
		$this->config = $config;
	}

	/**
	 * Reads image file and outputs contents via readfile() or GD function (ie. imagejpeg()). If the source is newer than the cache, it is automatically cleaned. Note that all output buffering is cleared!
	 */
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
			foreach (glob($this->config->resizerCachePath . '/' . $imageFile . "*") as $altFile) {
				// remove path, file name, and ext
				$width = preg_replace('/^.*-([0-9]+)\..*$/', '$1', $altFile);
				if (!is_numeric($width)) continue;
				$width = intval($width);
				if ($width < $size) continue; // too small!
				$cacheMap[intval($width)] = $altFile;
			}
			// sort by width
			ksort($cacheMap);
			// anything in here? use the smallest possible
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

		if (!$this->config->useCache || !file_exists($cacheFile)) {
			// no cache setting, or no cache for this size
			$createCache = TRUE;
			$cacheExists = FALSE;
		} else if ($sourceTime > filemtime($cacheFile)) {
			// source was updated, clear cache
			try {
				unlink($cacheFile);
			} catch (\Exception $e) {
				log_message('error', 'Resizer Lib cannot unlink file ' . $cacheFile);
			}
			$createCache = TRUE;
			$cacheExists = FALSE;
		} else {
			// cache is valid
			$createCache = FALSE;
			$cacheExists = TRUE;
		}

		if (!$cacheExists) {
			// read the image size (performance hit!) and determine if its out of bounds
			$this->imageLib->withFile($altCache ?? $sourceFile);
			// upscale check
			if ($this->config->allowUpscale) {
				$sourceWidth = $this->imageLib->getWidth();
				$sourceHeight = $this->imageLib->getHeight();
				if ($sourceWidth < $size && $sourceHeight < $size) {
					$size = max($sourceWidth, $sourceHeight);
					$cacheFile = $this->cacheFile($imageFile, $size, $ext);
					$cacheExists = file_exists($cacheFile);
				}
			}
			// maxSize check
			if ($this->config->maxSize && $size > $this->config->maxSize) {
				$size = $this->config->maxSize;
				$cacheFile = $this->cacheFile($imageFile, $size, $ext);
				$cacheExists = file_exists($cacheFile);
			}
		}

		// check cache file once again
		if (!$cacheExists) {
			// resize the image (performance hit!)
			$this->imageLib->resize($size, $size, TRUE, 'width');
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

	/**
	 * Cleans the entire cache dir. Uses filemtime or forced deletion. Returns an array of files deleted.
	 */
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

	/**
	 * Cleans all cache files for a given image. Returns an array of files deleted.
	 */
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

	/**
	 * Returns a public URL for a given image file and size. This is useful for generating image URLs in views.
	 */
	public function publicFile(string $imageFile, int $size, string $ext = '.jpg'): string
	{
		return $this->config->rewriteSegment . '/' . $imageFile . $this->config->rewriteSizeSep . $size . $ext;
	}

	/**
	 * Returns the path to the source file. This is useful for working with server-side file operations.
	 */
	public function sourceFile(string $imageFile, string $ext = '.jpg'): string
	{
		return $this->config->realImagePath . '/' . $imageFile . $ext;
	}

	/**
	 * Gets the path + name of the cache file, and ensures the path exists.
	 */
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
