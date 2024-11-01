1.composer create-project composer/satis --stability=dev --keep-vcs
2.composer config --global --auth github-oauth.github.com <token> token从github获取
3.composer install，需要给logs,temp文件夹读写权限
4./www/server/php/82/bin/php webhook.php satis-hook:build
5.https://composer.phelotto.com/webhook.php?build-all=1&api-key=05cb41083cd9778fb0f0f4d33f8388f2

php satis/bin/satis build satis.json composer --skip-errors


php satis/bin/satis build satis.json composer/ richard/payment

composer require shopen-group/satis-hook dev-develop --ignore-platform-reqs

/www/wwwroot/composer.phelotto.com/packages


screen -S composer 创建绘画

screen -ls 绘画列表

screen -r composer 查看绘画

screen -S 1212121.composer(绘画Id) -X quit 删除绘画



