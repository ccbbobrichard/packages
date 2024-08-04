1.composer create-project composer/satis --stability=dev --keep-vcs
2.composer config --global --auth github-oauth.github.com <token> token从github获取
3.composer install，需要给logs,temp文件夹读写权限
4./www/server/php/82/bin/php webhook.php satis-hook:build
5.https://composer.phelotto.com/webhook.php?build-all=1&api-key=05cb41083cd9778fb0f0f4d33f8388f2

php satis/bin/satis build satis.json composer --skip-errors


php satis/bin/satis build satis.json composer/ richard/payment

composer require shopen-group/satis-hook dev-develop --ignore-platform-reqs

The command &quot;&#x27;/www/server/php/82/bin/php&#x27; &#x27;/www/wwwroot/composer.phelotto.com/packages/./satis/bin/satis&#x27; &#x27;build&#x27; &#x27;/www/wwwroot/composer.phelotto.com/packages/./satis.json&#x27; &#x27;/www/wwwroot/composer.phelotto.com/packages/./composer&#x27; &#x27;-n&#x27;&quot; failed.


/www/wwwroot/composer.phelotto.com/packages

