<?php

namespace Tomkirsch\Resizer;

use CodeIgniter\Config\BaseConfig;

class ResizerConfig extends BaseConfig
{
	public string $rewriteSegment = 'imagerez'; // this "folder" lets htaccess know to rewrite the request to Resizer controller. must match your .htaccess file regex
	// RewriteRule ^imagerez\/(.+)-([0-9]+)\.(.+) resizer/read?file=$1&size=$2&ext=$3 [NC,QSA]
	public string $rewriteSizeSep = '-'; // separator from base file name and requested size. must match your .htaccess file regex
	public string $realImagePath = ROOTPATH . '/public'; // real path to source images
	public bool $useCache = TRUE; // turn off file cache for testing
	public string $resizerCachePath = ROOTPATH . '/writable/resizercache'; // path to store cached image files
	public int $ttl = 60 * 60 * 24 * 7; // clean cached images older than this (seconds)
	public int $randomCleanChance = 100; // library will auto clean cache folder upon file read - helps clean cache of deleted images
}
