<?php

namespace Tomkirsch\Resizer;

use CodeIgniter\Config\BaseConfig;

class ResizerConfig extends BaseConfig
{
	public bool $useCache = TRUE; // turning this off may cause performance issues
	public bool $allowUpscale = FALSE; // whether to allow upscaling images, recommended FALSE (note this could break a server if a user requests a very large image)
	public int $maxSize = 0; // max output size for all images, set to 0 to disable

	public string $rewriteSegment = 'img'; // this "folder" lets htaccess know to rewrite the request to Resizer controller. must match your public/.htaccess file regex
	public string $rewriteSizeSep = '-'; // separator from base file name and requested size. must match your .htaccess file regex

	public string $realImagePath = ROOTPATH . '/public'; // real path to source images. this can be a private folder.
	public string $resizerCachePath = ROOTPATH . '/writable/resizercache'; // path to store cached image files. be careful with this path, it can be wiped out when clearing cache!
	public bool $addBaseUrl = TRUE; // whether to add base_url() to the output of publicFile()

	public int $ttl = 60 * 60 * 24 * 7; // clean cached images older than this (seconds). Whenever a cache is accessed, it resets the timer
	public float $randomCleanChance = 0.01; // chance of cleaning expired images on each image request (default at 10%). set to 0 to disable
	public ?string $cacheControlHeader = 'public, max-age=2592000'; // Cache-Control header for browser caching (default is 30 days)
	public bool $debugHeaders = FALSE; // whether to output extra headers for debugging

	public array $pictureDefaultBreakpoints = [576, 768, 992, 1200, 1400]; // default sizes for picture element, based off bootstrap breakpoints
	public array $pictureDefaultDprs = [1, 2]; // default device pixel ratios to support
	public string $pictureDefaultSourceExt = '.jpg'; // default source extension for picture element
	public string $pictureDefaultDestExt = ''; // default output extension for picture element. leave empty to use source extension by default.
	public bool $pictureDefaultLazy = FALSE; // default lazy loading for picture element
	public string $pictureDefaultLowRes = 'pixel64'; // low quality image placeholder: 'pixel64' (transparent pixel), 'first', 'last', 'custom', or supply the name to be appended to the file option
	public string $pictureNewlines = "\n"; // newlines for picture element output, set to '' for minified output
}
