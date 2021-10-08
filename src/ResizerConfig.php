<?php namespace Tomkirsch\Resizer;

use CodeIgniter\Config\BaseConfig;

class ResizerConfig extends BaseConfig{
	public $rewriteSegment = 'imagerez'; // this "folder" lets htaccess know to rewrite the request to Resizer controller. must match your .htaccess file regex
	public $rewriteSizeSep = '-'; // separator from base file name and requested size. must match your .htaccess file regex
	public $realImagePath = ROOTPATH . '/public'; // real path to source images
	public $useCache = TRUE; // turn cache on/off
	public $resizerCachePath = ROOTPATH . '/writable/resizercache'; // path to store cached image files
	public $ttl = 60 * 60 * 24 * 1; // clean cached images older than 1 day
	public $randomCleanChance = 100; // library will auto clean cache folder upon file read - helps clean cache of deleted images
}