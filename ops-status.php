<?php
$s = imagecreatetruecolor(170, 120); imagefill($s, 0, 0, imagecolorallocate($s, 255, 255, 255)); $k = imagecolorallocate($s, 0, 0, 0);
imagestring($s, 5, 10, 20, 'ORAL SURGERY', $k); imagestring($s, 2, 10, 60, 'Peterson Ellis', $k); imagestring($s, 1, 10, 100, 'ISBN 978-0-323-41492-0', $k);
$b = imagecreatetruecolor(1360, 960); imagecopyresampled($b, $s, 0, 0, 0, 0, 1360, 960, 170, 120);
$p = sys_get_temp_dir().'/cover-test.jpg'; imagejpeg($b, $p, 90);
try { $r = app(App\Services\Library\CoverText::class)->read($p); echo "COVERTEXT: ".json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; } catch (Throwable $e) { echo "COVERTEXT ERR: ".$e->getMessage()."\n"; }
@unlink($p);
