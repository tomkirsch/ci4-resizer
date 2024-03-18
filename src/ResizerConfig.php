<?php

namespace Tomkirsch\Resizer;

use CodeIgniter\Config\BaseConfig;

class ResizerConfig extends BaseConfig
{
	public string $rewriteSegment = 'imagerez'; // this "folder" lets htaccess know to rewrite the request to Resizer controller. must match your .htaccess file regex
	// RewriteRule ^imagerez\/(.+)-([0-9]+)\.(.+) resizer/read?file=$1&size=$2&ext=$3 [NC,QSA]
	public string $rewriteSizeSep = '-'; // separator from base file name and requested size. must match your .htaccess file regex
	public string $realImagePath = ROOTPATH . '/public'; // real path to source images
	public bool $useCache = false; // turn off file cache for testing
	public string $resizerCachePath = ROOTPATH . '/writable/resizercache'; // path to store cached image files
	public int $ttl = 60 * 60 * 24 * 7; // clean cached images older than this (seconds)
	public int $randomCleanChance = 100; // library will auto clean cache folder upon file read - helps clean cache of deleted images
	public bool $allowUpscale = FALSE; // whether to allow upscaling images (note this could break a server if a user requests a very large image)
	public int $maxSize = 0; // max output size for images. set to 0 to disable
}
