<?php
declare(strict_types=1);
$pid=(int)@file_get_contents('/tmp/supervisord.pid');
exit($pid>0 && posix_kill($pid,0)?0:1);
