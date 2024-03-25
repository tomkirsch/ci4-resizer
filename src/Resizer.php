<?php

namespace Tomkirsch\Resizer;

use CodeIgniter\Images\Handlers\BaseHandler;

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
	 * Reads image file and outputs contents via readfile() or GD function (ie. imagejpeg()). If the source is newer than the cache, it is automatically cleaned. Note that all output buffering is cleared!
	 */
	public function read(string $imageFile, int $size, ?string $sourceExt = NULL, ?string $destExt = NULL)
	{
		$sourceExt = $this->ensureDot($sourceExt ?? $this->config->pictureDefaultSourceExt);
		if ($destExt === NULL) {
			$destExt = empty($this->config->pictureDefaultDestExt) ? $sourceExt : $this->config->pictureDefaultDestExt;
		}
		$destExt = $this->ensureDot($destExt);

		// random clean
		if ($this->config->useCache && $this->randomFloat() < $this->config->randomCleanChance) {
			$cleaned = $this->cleanDir(FALSE);
			log_message('debug', 'Resizer Lib cleaned cache of ' . count($cleaned) . ' files.');
		}

		// load GD image lib
		if (!$this->imageLib) $this->imageLib = \Config\Services::image('gd');
		$sourceFile = $this->sourceFile($imageFile, $sourceExt);
		$cacheFile = $this->cacheFile($imageFile, $size, $sourceExt, $destExt);
		$sourceTime = filemtime($sourceFile);

		// find an alternate cache if the correct size is not available
		$altSource = NULL;
		if (!file_exists($cacheFile)) {
			$cacheMap = [];
			foreach (glob($this->config->resizerCachePath . '/' . $imageFile . "*") as $altFile) {
				// match the filename pattern
				$matches = [];
				// note that the dest ext is NOT optional, it should be in ALL file names
				preg_match('/(.+)-([0-9]+)([.]\w+)([.]\w+)/', $altFile, $matches);
				if (count($matches) < 5) {
					log_message('debug', 'Resizer Lib encountered an improperly named cache file ' . $altFile);
					continue;
				}
				list($altBaseName, $width, $altSourceExt, $altDestExt) = array_slice($matches, 1);
				if (!is_numeric($width) || $altDestExt !== $destExt) continue; // ensure cache file is in the same format as the destination extension
				$width = intval($width);
				if ($width < $size) continue; // too small!
				$cacheMap[$width] = $altFile;
			}
			// sort by width
			ksort($cacheMap);
			// anything in here? use the smallest possible
			if (count($cacheMap)) $altSource = array_shift($cacheMap);
			// ensure alt cache filemtime is older than source
			if ($altSource && filemtime($altSource) > $sourceTime) {
				try {
					unlink($altSource);
				} catch (\Exception $e) {
					log_message('error', 'Resizer Lib cannot unlink file ' . $altSource);
				}
				$altSource = NULL;
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
			$this->imageLib->withFile($altSource ?? $sourceFile); // try to read from the smaller alt source if it exists
			// disallow upscale check
			if (!$this->config->allowUpscale) {
				$sourceWidth = $this->imageLib->getWidth();
				$sourceHeight = $this->imageLib->getHeight();
				if ($sourceWidth <= $size && $sourceHeight <= $size) {
					$size = max($sourceWidth, $sourceHeight);
					$cacheFile = $this->cacheFile($imageFile, $size, $sourceExt, $destExt);
					$cacheExists = file_exists($cacheFile);
				}
			}
			// maxSize check
			if ($this->config->maxSize && $size > $this->config->maxSize) {
				$size = $this->config->maxSize;
				$cacheFile = $this->cacheFile($imageFile, $size, $sourceExt, $destExt);
				$cacheExists = file_exists($cacheFile);
			}
		}

		// not using cache? then always create the output file
		if (!$this->config->useCache) {
			$cacheExists = FALSE;
			$createCache = FALSE;
		}

		// check cache file once again, and generate the image if necessary
		if (!$cacheExists) {
			// read image width if we haven't already
			if (!$this->imageLib->getWidth()) {
				$this->imageLib->withFile($altSource ?? $sourceFile);
			}
			// resize the image (performance hit!)
			$this->imageLib->resize($size, $size, TRUE, 'width');
			if ($createCache) {
				$this->imageLib->save($cacheFile);
				$cacheExists = TRUE;
			}
		} else {
			// cache exists, touch the file to update the mtime
			touch($cacheFile);
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
	 * Returns a public URL for a given image file and size. This is useful for generating image URLs in views.
	 */
	public function publicFile(string $imageFile, int $size, ?string $sourceExt = NULL, ?string $destExt = NULL): string
	{
		if ($sourceExt === NULL) {
			$sourceExt = $this->config->pictureDefaultSourceExt;
		}
		$sourceExt = $this->ensureDot($sourceExt);
		if ($destExt === NULL) {
			$destExt = empty($this->config->pictureDefaultDestExt) ? $sourceExt : $this->config->pictureDefaultDestExt;
		}
		$destExt = $this->ensureDot($destExt);
		if ($sourceExt === $destExt) $destExt = ''; // remove dest ext if it's the same as source

		$url = $this->config->rewriteSegment . '/' . $imageFile . $this->config->rewriteSizeSep . $size . $sourceExt . $destExt;
		if ($this->config->addBaseUrl) $url = base_url($url);
		return $url;
	}

	/**
	 * Returns the path to the source file. This is useful for working with server-side file operations.
	 */
	public function sourceFile(string $imageFile, ?string $sourceExt = NULL): string
	{
		$sourceExt = $this->ensureDot($sourceExt ?? $this->config->pictureDefaultSourceExt);
		return $this->config->realImagePath . '/' . $imageFile . $sourceExt;
	}

	/**
	 * Gets the path + name of the cache file, and ensures the path exists.
	 */
	public function cacheFile(string $imageFile, int $size, ?string $sourceExt = NULL, ?string $destExt = NULL): string
	{
		$sourceExt = $this->ensureDot($sourceExt ?? $this->config->pictureDefaultSourceExt);
		$destExt = $this->ensureDot($destExt ?? empty($this->config->pictureDefaultDestExt) ? $sourceExt : $this->config->pictureDefaultDestExt);

		// ensure source file exists before we create directories!
		$sourceFile = $this->sourceFile($imageFile, $sourceExt);
		if (!is_file($sourceFile)) {
			throw new \Exception("Cannot find source file $sourceFile");
		}
		$cacheDir = $this->config->resizerCachePath;
		$cacheFileName = $imageFile . '-' . $size . $sourceExt . $destExt;
		$cacheFile = "$cacheDir/$cacheFileName";
		$pathinfo = pathinfo($cacheFile);
		if (!is_dir($pathinfo['dirname'])) {
			mkdir($pathinfo['dirname'], 0777, TRUE);
		}
		return $cacheFile;
	}

	/**
	 * Returns a list of cache files for a given image file. Useful for cleaning deleted files.
	 */
	public function cacheFiles(string $imageFile): array
	{
		$files = [];
		$search = $this->config->resizerCachePath . '/' . $imageFile . '*';
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

	protected function ensureDot(string $ext): string
	{
		if (empty($ext)) throw new \Exception("Resizer requires a file extension");
		return substr($ext, 0, 1) === '.' ? $ext : '.' . $ext;
	}
}
