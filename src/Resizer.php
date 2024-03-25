<?php

namespace Tomkirsch\Resizer;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Images\Handlers\BaseHandler;

/**
 * Stores information about a base image file, so we can create consitently named files
 */
class ResizerImage
{
	/**
	 * The image path and BASE file name (with no extension)
	 */
	public string $baseName;
	/**
	 * The requested width of the image
	 */
	public int $width;
	/**
	 * The source file extension. If NULL, it uses the default from the config.
	 */
	public ?string $sourceExt;
	/**
	 * The destination file extension. If NULL, it is assumed to be the same as the source.
	 */
	public ?string $destExt;

	public function __construct(string $baseName, int $width, string $sourceExt, string $destExt)
	{
		$this->baseName = $baseName;
		$this->width = $width;
		$this->sourceExt = $sourceExt;
		$this->destExt = $destExt;
	}
}

/**
 * Automagically resize a source image with caching using CI's GD image library.
 * If .htaccess doesn't seem to want to recognize the path, try setting CI's App config $uriProtocol to PATH_INFO
 * Includes a picture() utility to generate responsive images with srcset and sizes attributes.
 **/
class Resizer
{
	const VERSION = '2.0.0';

	public ?ResizerConfig $config = NULL;
	public ?BaseHandler $imageLib = NULL;

	public function __construct(ResizerConfig $config)
	{
		$this->config = $config;
	}

	/**
	 * Parses an image request to get the base name, width, source extension, and destination extension.
	 * If your .htaccess uses a different rewrite rule, you may need to override this method.
	 */
	public function parseRequest(string $file): ResizerImage
	{
		$matches = [];
		preg_match('/(.+)' . $this->config->rewriteSizeSep . '([0-9]+)([.]\w+)([.]\w+)?/', $file, $matches);
		if (empty($matches) || count($matches) < 4) {
			throw new \Exception("Invalid image request: $file");
		}
		$sourceExt = $this->ensureDot($matches[3] ?? $this->config->pictureDefaultSourceExt);
		// if no dest extension was given, use the config default. If that is an empty string, use the source extension.
		$defaultDest = empty($this->config->pictureDefaultDestExt) ? $sourceExt : $this->config->pictureDefaultDestExt;
		$destExt = $this->ensureDot($matches[4] ?? $defaultDest);
		return new ResizerImage($matches[1], intval($matches[2]), $sourceExt, $destExt);
	}

	/**
	 * Parses a cache file to get the base name, width, source extension, and destination extension.
	 * This must line up with makeCacheFilename()
	 */
	protected function parseCacheFilename(string $file): ResizerImage
	{
		// ensure path isnt absolute
		$file = str_replace($this->config->resizerCachePath, '', $file);
		$matches = [];
		// note this is an internal pattern, cache file naming is not user-facing
		preg_match('/(.+)-([0-9]+)([.]\w+)([.]\w+)/', $file, $matches);
		if (empty($matches) || count($matches) < 5) {
			throw new \Exception("Invalid cache file: $file");
		}
		return new ResizerImage($matches[1], intval($matches[2]), $this->ensureDot($matches[3]), $this->ensureDot($matches[4]));
	}

	/**
	 * Returns the real path to the source file. You may pass a ResizerImage object or the individual components.
	 */
	public function sourceFile($baseName, ?string $sourceExt = NULL): string
	{
		if (is_a($baseName, ResizerImage::class)) {
			$sourceExt = $baseName->sourceExt;
			$baseName = $baseName->baseName;
		}
		$sourceExt = $this->ensureDot($sourceExt ?? $this->config->pictureDefaultSourceExt);
		return $this->config->realImagePath . '/' . $baseName . $sourceExt;
	}

	/**
	 * Returns a public URL for a given image file and size
	 */
	public function publicFile(string $baseImage, int $size, ?string $sourceExt = NULL, ?string $destExt = NULL): string
	{
		$sourceExt ??= $this->config->pictureDefaultSourceExt;
		$sourceExt = $this->ensureDot($sourceExt);
		if ($destExt === NULL) {
			$destExt = empty($this->config->pictureDefaultDestExt) ? $sourceExt : $this->config->pictureDefaultDestExt;
		}
		$destExt = $this->ensureDot($destExt);
		if ($sourceExt === $destExt) $destExt = ''; // remove dest ext if it's the same as source to declutter URLs
		$sep = substr($this->config->rewriteSegment, -1) === '/' ? '' : '/';
		$url = $this->config->rewriteSegment . $sep . $baseImage . $this->config->rewriteSizeSep . $size . $sourceExt . $destExt;
		if ($this->config->addBaseUrl) $url = base_url($url);
		return $url;
	}

	/**
	 * Standardizes the cache file name format. You may pass a ResizerImage object or the individual components.
	 * This must line up with parseCacheFilename()
	 */
	protected function cacheFile($baseName, ?int $width = NULL, ?string $sourceExt = NULL, ?string $destExt = NULL): string
	{
		if (is_a($baseName, ResizerImage::class)) {
			$width = $baseName->width;
			$sourceExt = $baseName->sourceExt;
			$destExt = $baseName->destExt;
			$baseName = $baseName->baseName; // set this last!
		} else {
			$sourceExt = $this->ensureDot($sourceExt);
			$destExt = $this->ensureDot($destExt);
		}
		$cacheDir = $this->config->resizerCachePath;
		$cacheFile = "$cacheDir/$baseName-$width$sourceExt$destExt";
		// ensure cache directory exists
		$pathinfo = pathinfo($cacheFile);
		if (!is_dir($pathinfo['dirname'])) {
			mkdir($pathinfo['dirname'], 0777, TRUE);
		}
		return $cacheFile;
	}

	protected function ensureDot(string $ext): string
	{
		return substr($ext, 0, 1) === '.' ? $ext : '.' . $ext;
	}

	/**
	 * Looks at the cache files for the given base file and width, and returns the smallest possible file that can be used or NULL.
	 */
	protected function findAltSource(string $baseFile, int $width, string $destExt): ?ResizerImage
	{
		$altSource = NULL;
		$cacheMap = [];
		foreach ($this->cacheFiles($baseFile) as $altFile) {
			try {
				$altRequest = $this->parseCacheFilename($altFile);
			} catch (\Exception $e) {
				log_message('debug', 'Resizer Lib encountered an improperly named cache file ' . $altFile);
				continue;
			}
			// ensure cache file is in the same format as the destination extension
			//if ($altRequest->destExt !== $destExt) continue;
			// ensure cache file is larger than the requested width
			if ($altRequest->width < $width) continue;
			$cacheMap[$altRequest->width] = $altRequest;
		}
		// anything in here? use the smallest possible
		if (count($cacheMap)) {
			// sort by width keys
			ksort($cacheMap);
			$altSource = array_shift($cacheMap);
		}
		return $altSource;
	}

	/**
	 * Reads image file and outputs contents via readfile() or GD function (ie. imagejpeg()). If the source is newer than the cache, it is automatically cleaned.
	 * Pass $dpr to use a device pixel ratio, which will multiply the size of the image.
	 * Note that all output buffering is cleared!
	 */
	public function read(string $requestedFile, float $dpr = 1.0)
	{
		$imageRequest = $this->parseRequest($requestedFile);
		$requestedWidth = round($imageRequest->width * $dpr);
		$sourceExt = $imageRequest->sourceExt;
		$destExt = $imageRequest->destExt;

		// random clean chance
		if ($this->config->useCache && $this->randomFloat() < $this->config->randomCleanChance) {
			$cleaned = $this->cleanDir(FALSE);
			log_message('debug', 'Resizer Lib cleaned cache of ' . count($cleaned) . ' files.');
		}

		// load GD image lib
		if (!$this->imageLib) $this->imageLib = \Config\Services::image('gd');

		// ensure source exists
		$sourceFile = $this->sourceFile($imageRequest->baseName, $sourceExt);
		if (!file_exists($sourceFile)) {
			throw new PageNotFoundException("Image not found: $sourceFile");
		}
		// read source file mtime
		$sourceTime = filemtime($sourceFile);

		// find a smaller source file if it's in the cache list
		if ($altSource = $this->findAltSource($imageRequest->baseName, $requestedWidth, $destExt)) {
			$altSourceFile = $this->cacheFile($altSource);
			if ($sourceTime > filemtime($altSourceFile)) {
				// source was updated, clear cache
				try {
					unlink($altSourceFile);
				} catch (\Exception $e) {
					log_message('error', 'Resizer Lib cannot unlink file ' . $altSourceFile);
				}
				$altSource = NULL;
				$altSourceFile = NULL;
				if ($this->config->debugHeaders) header("X-Resizer-Outdated-Alt: $altSourceFile");
			}
		}
		// use the alt source file if it exists
		$actualSourceFile = $altSourceFile ?? $sourceFile;

		// generate cache file name for the requested image
		$cacheFile = $this->cacheFile($imageRequest->baseName, $requestedWidth, $sourceExt, $destExt);

		// check cache validity
		$createCache = TRUE;
		$cacheExists = FALSE;
		if (file_exists($cacheFile)) {
			if ($sourceTime > filemtime($cacheFile)) {
				// source was updated, clear cache
				try {
					unlink($cacheFile);
				} catch (\Exception $e) {
					log_message('error', 'Resizer Lib cannot unlink file ' . $cacheFile);
				}
				if ($this->config->debugHeaders) header("X-Resizer-Outdated: $altSourceFile");
			} else {
				// cache is valid
				$createCache = FALSE;
				$cacheExists = TRUE;
			}
		}

		// validate requested width (and implied height) against source image size
		$width = $requestedWidth;
		// we'll assume that if a cache file exists, the requested width is valid
		if (!$cacheExists) {
			// if no cache exists, read the image size (performance hit!) and determine if its out of bounds
			$this->imageLib->withFile($actualSourceFile);
			$sourceWidth = $this->imageLib->getWidth();
			$sourceHeight = $this->imageLib->getHeight();
			$ratio = $sourceWidth / $sourceHeight;
			$requestedHeight = round($requestedWidth / $ratio);
			// disallow upscale check
			if (!$this->config->allowUpscale) {
				if ($requestedWidth > $sourceWidth) {
					// too wide
					$width = $sourceWidth;
				} else if ($requestedHeight > $sourceHeight) {
					// too tall
					$width = round($sourceHeight * $ratio);
				}
			}
			// maxSize check across both dimensions
			if ($this->config->maxSize) {
				if ($width > $this->config->maxSize) {
					$width = $this->config->maxSize;
				}
				// recaclulate height and check it
				$requestedHeight = $width / $ratio;
				if ($requestedHeight > $this->config->maxSize) {
					$requestedHeight = $this->config->maxSize;
					$width = round($requestedHeight * $ratio);
				}
			}
		}

		// $width is now valid, so we can resize the image

		// not using cache? then always create the output file
		if (!$this->config->useCache) {
			$cacheExists = FALSE;
			$createCache = FALSE;
		}

		// check cache file once again, and generate the image if necessary
		if (!$cacheExists) {
			// read image width if we haven't already
			if (!$this->imageLib->getWidth()) {
				$this->imageLib->withFile($actualSourceFile);
			}
			$sourceWidth = $this->imageLib->getWidth();
			$sourceHeight = $this->imageLib->getHeight();
			$ratio = $sourceWidth / $sourceHeight;
			// resize the image
			$this->imageLib->resize($width, round($width / $ratio));
			// save the image to the cache
			if ($createCache) {
				$this->imageLib->save($cacheFile);
				$cacheExists = TRUE;
			} else {
				$cacheFile = ""; // for the debug header
			}
		} else {
			// cache exists, touch the file to update the mtime
			touch($cacheFile);
			$actualSourceFile = $cacheFile;
		}

		// remove any CI output buffering
		ob_end_flush();

		// generate headers
		$extNoDot = substr($destExt, 1);
		$mime = $this->mimeFromExt($extNoDot);
		if ($mime) header("Content-Type: $mime");
		$headerDate = gmdate('D, d M Y H:i:s T', $sourceTime);
		header("Last-Modified: $headerDate");
		if ($this->config->cacheControlHeader) header("Cache-control: " . $this->config->cacheControlHeader);
		if ($this->config->debugHeaders) {
			header("X-Resizer-Source: $sourceFile");
			header("X-Resizer-Actual-Source: $actualSourceFile");
			header("X-Resizer-Cache: $cacheFile");
			header("X-Resizer-Cache-Exists: " . ($cacheExists ? 'true' : 'false'));
		}
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
	 * This should be done occasionally, to ensure the cache doesn't grow too large.
	 */
	public function cleanDir(bool $forceCleanAll = FALSE): array
	{
		// loop through all files in the cache directory
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
			// delete file if $forceCleanAll, or file mtime is older than our ttl
			$expired = $this->config->ttl ? $info->getMTime() + $this->config->ttl < time() : FALSE;
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
		$files = $this->cacheFiles($imageFile);
		foreach ($files as $file) {
			try {
				unlink($file);
			} catch (\Exception $e) {
				log_message('error', 'Resizer Lib cannot unlink file ' . $file);
			}
		}
		return $files;
	}

	/**
	 * Returns a list of cache files for a given image file. Useful for cleaning deleted files.
	 */
	public function cacheFiles(string $imageFile): array
	{
		$files = [];
		$sep = substr($this->config->resizerCachePath, -1) === '/' ? '' : '/';
		$search = $this->config->resizerCachePath . $sep . $imageFile . '*';
		foreach (glob($search) as $file) {
			$files[] = $file;
		}
		return $files;
	}


	/**
	 * Returns a picture element with source set to the public URL. Options:
	 * - file: (required) path and base file name without ext, ex: 'img/foo-bar'
	 * - sourceExt: (optional) source file extension. Default is in config.
	 * - destExt: (optional) destination file extension. Default is in config.
	 * - breakpoints: (optional) list the screen width breakpoints to support. Default is in config.
	 * - dprs: (optional) for dpr mode, list the device pixel ratios to support. Default is in config.
	 * - newlines: (optional) add newlines for readability. Default is in config.
	 * - lazy: (optional) use data-src or data-srcset with lowres placeholder. Default is in config.
	 * - lowres: (optional)  'pixel64' (transparent pixel), 'first', 'last', 'custom', or supply the name to be appended to the file option. Default is in config.
	 * - lowrescustom: (optional) custom HTML for src when lowres === custom
	 * Note that every element in $sources will inherit the $options array, so you can set defaults there.
	 */
	public function picture(array $attr, array $imgAttr, array $options, ...$additionalSources): string
	{
		// merge default options from config
		$options = array_merge(
			[
				'file' => '',
				'sourceExt' => $this->config->pictureDefaultSourceExt,
				'destExt' => $this->config->pictureDefaultDestExt,
				'breakpoints' => $this->config->pictureDefaultBreakpoints,
				'dprs' => $this->config->pictureDefaultDprs,
				'newlines' => $this->config->pictureNewlines,
				'lazy' => $this->config->pictureDefaultLazy,
				'lowres' => $this->config->pictureDefaultLowRes,
				'lowrescustom' => '',
			],
			$options
		);

		// sanity
		if (empty($options['file']))  throw new \Exception("Resizer picture() requires a file option");
		if (empty($options['sourceExt'])) throw new \Exception("Resizer picture() requires an sourceExt option");
		if (empty($options['breakpoints'])) throw new \Exception("Resizer picture() requires breakpoints");
		if (empty($options['dprs'])) $options['dprs'] = [1];
		$options['sourceExt'] = $this->ensureDot($options['sourceExt']);
		$options['destExt'] = $this->ensureDot(empty($options['destExt']) ? $options['sourceExt'] : $options['destExt']); // inherit source ext if not set
		$options['lazy'] = (bool) $options['lazy'];
		$nl = (string) $options['newlines'];

		$out = '<picture' . stringify_attributes($attr) . '>';

		// print the additional sources first. browser will use the first element with a matching hint and ignore any following tags, so these are prioritized
		foreach ($additionalSources as $source) {
			// allow us to inherit $options
			$out .= $this->pictureSource(array_merge($options, $source));
		}

		// now handle the "default" source in $options
		// we need <source>s for each screenwidth. this causes some bloat, but it's the only way to support dpr AND screen width
		$list = $options['breakpoints'];
		rsort($list); // sort from largest to smallest
		$lastKey = array_key_last($list); // this will not have a media query
		foreach ($list as $screenwidth) {
			$sourceOptions = array_merge($options, [
				'breakpoints' => [$screenwidth],
			]);
			// only use media query if this is not the last element
			if ($screenwidth !== $list[$lastKey]) {
				$sourceOptions['media'] = "(min-width: $screenwidth" . "px)";
			}
			$out .= $this->pictureSource(array_merge($options, $sourceOptions));
		}

		// img lowres src (fallback for browsers that don't support <picture> or JS)
		$imgAttr['src'] = $this->lowresSource($options);
		$out .= $nl . '<img' . stringify_attributes($imgAttr) . '>';
		$out .= $nl . '</picture>';
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
				'breakpoints' => [], // this should already be set... specifying it here for compiler
			],
			$options
		);
		$nl = (string) $options['newlines'];
		$out = $nl . '<source';
		if (!empty($options['media'])) $out .= ' media="' . $options['media'] . '"';
		$attr = $options['lazy'] ? 'data-srcset' : 'srcset';
		$out .= ' ' . $nl . $attr . '="' . implode(', ' . $nl, $this->pictureSourceSet($options)) . '"';
		$out .= '>' . $nl;
		return $out;
	}

	/**
	 * Returns a srcset numeric array for a given set of options. Used by picture() and pictureSource().
	 */
	protected function pictureSourceSet(array $options): array
	{
		$srcsets = []; // each screenwidth will be a numeric key, and the value will be the srcset string
		foreach ($options['breakpoints'] as $screenwidth) {
			$file = $this->publicFile($options['file'], $screenwidth, $options['sourceExt'], $options['destExt']);
			// add dpr query and x factor for each screenwidth
			foreach ($options['dprs'] as $dpr) {
				$dpr = floatval($dpr);
				$xtra = ($dpr === 1.0) ? "" : "?dpr=$dpr $dpr" . "x"; // leave 1x descriptors out. note the float comparison!
				$srcsets[intval($screenwidth) * $dpr] = $file . $xtra;
			}
		}
		return $srcsets;
	}

	protected function lowresSource(array $options): string
	{
		$out = '';
		switch ($options['lowres']) {
			case 'pixel64':
				$out = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
				break;
			case 'first':
				$key = array_key_first($options['breakpoints']);
				$out = $this->publicFile($options['file'], $options['breakpoints'][$key], $options['destExt']);
				break;
			case 'last':
				$key = array_key_last($options['breakpoints']);
				$out = $this->publicFile($options['file'], $options['breakpoints'][$key], $options['destExt']);
				break;
			case 'custom':
				$out = $options['lowrescustom'];
				break;
			default:
				$out = $options['file'] . $options['lowres'] . $options['destExt'];
		}
		return $out;
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
			case 'avif':
				if (!defined('IMAGETYPE_AVIF')) break; // (PHP 8 >= 8.1.0)
				$mime = image_type_to_mime_type(IMAGETYPE_AVIF);
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
			case 'avif': // (PHP 8 >= 8.1.0)
				$func = 'imageavif';
				break;
		}
		$func($this->imageLib->getResource());
	}

	protected function randomFloat($min = 0, $max = 1, $includeMax = FALSE): float
	{
		return $min + mt_rand(0, (mt_getrandmax() - ($includeMax ? 0 : 1))) / mt_getrandmax() * ($max - $min);
	}
}
