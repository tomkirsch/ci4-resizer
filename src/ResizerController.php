<?php

namespace Tomkirsch\Resizer;

use CodeIgniter\Controller;

// note we don't use BaseController here
class ResizerController extends Controller
{

    protected ?Resizer $resizer;

    public function __construct()
    {
        $this->resizer = \Config\Services::resizer();
    }

    // output image to browser
    public function read()
    {
        $request = \Config\Services::request();
        $imageFile = $request->getGet('file');
        if (empty($imageFile)) throw new \Exception('No file given!');
        $size = $request->getGet('size');
        if (empty($size)) throw new \Exception('No size given!');
        $sourceExt = $request->getGet('sourceExt');
        if (empty($sourceExt)) throw new \Exception('No source extention given!');
        $destExt = $request->getGet('destExt');
        if (empty($destExt)) $destExt = NULL;

        // read the device pixel ratio
        $dpr = $request->getGet('dpr') ?? 1;
        $size = floor(intval($size) * floatval($dpr));

        // generate cache file and spit out the actual image
        $this->resizer->read($imageFile, $size, $sourceExt, $destExt);
        // exit the script
        exit;
    }

    // cleanup cache for given file pattern (uses stristr() for partial matches, so be specific!)
    public function cleanfile()
    {
        $request = \Config\Services::request();
        $filePattern = $request->getGet('file');
        if (empty($filePattern)) throw new \Exception('No file given!');
        dd($this->resizer->cleanFile($filePattern));
    }

    // cleanup all cache. pass ?force=1 to delete all files regardless of age
    public function cleandir()
    {
        $request = \Config\Services::request();
        dd($this->resizer->cleanDir((bool) $request->getGet('force')));
    }
}
