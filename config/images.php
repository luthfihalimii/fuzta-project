<?php

return [

    /*
    | Disk aktif untuk foto produk. 'public' untuk dev/test
    | (tanpa kredensial); 'r2' di VPS: set IMAGES_DISK=r2 + env R2_*.
    |
    */

    'disk' => env('IMAGES_DISK', 'public'),

];
