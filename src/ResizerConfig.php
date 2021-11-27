<?php namespace Tomkirsch\Resizer;

use CodeIgniter\Config\BaseConfig;

class ResizerConfig extends BaseConfig{
	public $rewriteSegment = 'imagerez'; // this "folder" lets htaccess know to rewrite the request to Resizer controller. must match your .htaccess file regex
	// RewriteRule ^imagerez\/(.+)-([0-9]+)\.(.+) resizer/read?file=$1&size=$2&ext=$3 [NC,QSA]
	public $rewriteSizeSep = '-'; // separator from base file name and requested size. must match your .htaccess file regex
	public $realImagePath = ROOTPATH . '/public'; // real path to source images
	public $useCache = TRUE; // turn off file cache for testing
	public $resizerCachePath = ROOTPATH . '/writable/resizercache'; // path to store cached image files
	public $ttl = 60 * 60 * 24 * 7; // clean cached images older than this (seconds)
	public $randomCleanChance = 100; // library will auto clean cache folder upon file read - helps clean cache of deleted images
}