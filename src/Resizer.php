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

		if (!file_exists($cacheFile)) {
			// no cache for this size
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

		// if no cache exists, read the image size (performance hit!) and determine if its out of bounds
		if (!$cacheExists) {
			$this->imageLib->withFile($altCache ?? $sourceFile);
			// disallow upscale check
			if (!$this->config->allowUpscale) {
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

		// using cache?
		if (!$this->config->useCache) {
			$cacheExists = FALSE;
			$createCache = FALSE;
		}

		// check cache file once again, and generate the image if necessary
		if (!$cacheExists) {
			// load the image if we haven't already
			if (!$this->imageLib->getWidth()) {
				$this->imageLib->withFile($sourceFile);
			}
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
		if ($this->config->cacheControlHeader) header("Cache-control: " . $this->config->cacheControlHeader);
		// if we have a cache file, read it
		if ($cacheExists) {
			header("Content-Length: " . filesize($cacheFile));
			readfile($cacheFile);
		} else {
			// image resource should still exist, output it to the browser using GD function
			$this->outputResource($extNoDot);
		}
		// you should exit the script after this
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

	/**
	 * Returns a picture element with source set to the public URL. This is useful for generating responsive images in views.
	 * Note that every element in $sources will inherit the $options array, so you can set defaults there.
	 */
	public function picture(array $attr, array $imgAttr, array $options, ...$sources): string
	{
		// ensure helper is loaded
		if (!function_exists('picture')) helper('html');

		// default options
		$options = array_merge(
			[
				'file' => '', // required. path and base file name, ex: 'img/foo-bar'
				'ext' => $this->config->pictureDefaultExt, // file extension with dot
				'mode' => $this->config->pictureDefaultMode, // 'screenwidth' (600w) or 'dpr' (2x)
				'screenwidths' => $this->config->pictureDefaultSizes, // for screenwidth mode, list the breakpoints to support
				'dprs' => $this->config->pictureDefaultDprs, // for dpr mode, list the device pixel ratios to support
				'newlines' => $this->config->pictureNewlines, // add newlines for readability
				'lazy' => $this->config->pictureDefaultLazy, // use data-src or data-srcset with lowres placeholder
				'lowres' => $this->config->pictureDefaultLowRes, // 'pixel64' (transparent pixel), 'first', 'last', 'custom', or supply the name to be appended to the file option
				'lowrescustom' => '', // custom HTML for src when lowres === custom
			],
			$options
		);

		// set data-sizes to "auto" if needed
		if ($options['lazy'] && $this->config->pictureDefaultAutoSizes && !isset($imgAttr['data-sizes'])) $imgAttr['data-sizes'] = 'auto';

		// remove base_url
		$options['file'] = str_replace(base_url(), '', $options['file']);
		// add the dog whistle for .htaccess
		$options['file'] = base_url($this->config->rewriteSegment . '/' . $options['file']);
		// add the final dash
		$options['file'] .= '-';

		$nl = $options['newlines'];
		$out = '<picture' . stringify_attributes($attr) . '>';
		// first source, if screenwidth mode
		if ($options['mode'] === 'screenwidth') $out .= $this->pictureSource($options);
		// additional sources
		foreach ($sources as $source) {
			$source = array_merge(
				$options,
				$source
			); // allow us to inherit $options
			$out .= $this->pictureSource($source);
		}
		// img src
		switch ($options['lowres']) {
			case 'pixel64':
				$imgAttr['src'] = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
				break;
			case 'first':
				$imgAttr['src'] = $options['file'] . $options['screenwidths'][0] . $options['ext'];
				break;
			case 'last':
				$imgAttr['src'] = $options['file'] . $options['screenwidths'][count($options['screenwidths']) - 1] . $options['ext'];
				break;
			case 'custom':
				$imgAttr['src'] = $options['lowrescustom'];
				break;
			default:
				$imgAttr['src'] = $options['file'] . $options['lowres'] . $options['ext'];
		}
		// if dpr mode, add first source as attributes
		if ($options['mode'] === 'dpr') {
			$name = $options['lazy'] ? 'data-srcset' : 'srcset';
			$imgAttr[$name] = implode(", $nl", $this->pictureSourceSet($options));
		}
		$out .= ' <img' . stringify_attributes($imgAttr) . '>';
		$out .= '</picture>';
		return $out;
	}

	/**
	 * Returns a <source> element with srcset and media attributes. Used by picture().
	 */
	protected function pictureSource(array $options): string
	{
		$options = array_merge(
			[
				'media' => NULL, // CSS media for this source, ex: '(min-width: 720px)'
				'screenwidths' => [], // this should already be set... specifying it here for compiler
			],
			$options
		);
		$nl = $options['newlines'];
		$out = $nl . '<source';
		if (!empty($options['media'])) $out .= ' media="' . $options['media'] . '"';
		if ($options['lazy'] && $options['mode'] == 'screenwidth') {
			$src = '';
			switch ($options['lowres']) {
				case 'pixel64':
					$src = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
					break;
				case 'first':
					$src = $options['file'] . $options['screenwidths'][0] . $options['ext'];
					break;
				case 'last':
					$src = $options['file'] . $options['screenwidths'][count($options['screenwidths']) - 1] . $options['ext'];
					break;
				case 'custom':
					$src = $options['lowrescustom'];
					break;
				default:
					$src = $options['file'] . $options['lowres'] . $options['ext'];
			}
			$out .= ' srcset="' . $src . '"';
		}

		$attr = $options['lazy'] ? 'data-srcset' : 'srcset';
		$out .= ' ' . $nl . $attr . '="' . implode(', ' . $nl, $this->pictureSourceSet($options)) . '"';
		$out .= '>' . $nl;
		return $out;
	}

	/**
	 * Returns a srcset string for a given set of options. Used by picture() and pictureSource().
	 */
	protected function pictureSourceSet(array $options): array
	{
		$srcsets = [];
		for ($i = 0; $i < count($options['screenwidths']); $i++) {
			$file = $options['file'] . $options['screenwidths'][$i] . $options['ext'];
			// if dpr mode, add dpr query and x factor
			if ($options['mode'] === 'dpr') {
				foreach ($options['dprs'] as $dpr) {
					$xtra = "?dpr=$dpr $dpr" . "x";
					$srcsets[] = $file . $xtra;
				}
			} else {
				// add w factor
				$wFactor = $options['screenwidths'][$i] ?? '';
				if ($wFactor) {
					$w = stristr($options['screenwidths'][$i], 'w') ? '' : 'w';
					$file .= ' ' . $options['screenwidths'][$i] . $w;
				}
				$srcsets[$wFactor] = $file; // note the key used here
			}
		}
		// if using screenwidth mode, ensure we have a default src if the screen width falls below the smallest size (in dpr mode, this situation is handled by the 1x src)
		if ($options['mode'] === 'screenwidth') {
			if (!isset($srcsets[''])) {
				$firstKey = array_key_first($srcsets);
				// remove w factor and set as the default src
				$srcsets[''] = preg_replace('/\s.*/', '', $srcsets[$firstKey]);
				unset($srcsets[$firstKey]);
			}
			ksort($srcsets); // sort by key
			$srcsets = array_values($srcsets); // reindex
		}
		return $srcsets;
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
