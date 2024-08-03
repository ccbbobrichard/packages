<?php
$output   = shell_exec('php /www/wwwroot/composer.phelotto.com/packages/satis/bin/satis build /www/wwwroot/composer.phelotto.com/packages/satis.json /www/wwwroot/composer.phelotto.com/packages/composer/ 2>&1');
print_r($output);
