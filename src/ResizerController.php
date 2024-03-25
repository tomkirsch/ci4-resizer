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
        $file = $request->getGet('file');
        if (empty($file)) throw new \Exception('No file given!');
        // read the device pixel ratio
        $dpr = $request->getGet('dpr') ?? 1;
        // generate cache file and spit out the actual image
        $this->resizer->read($file, floatval($dpr));
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
