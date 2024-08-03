composer create-project composer/satis --stability=dev --keep-vcs

php satis/bin/satis build satis.json composer --skip-errors


composer config --global --auth github-oauth.github.com <token>


php satis/bin/satis build satis.json composer/ richard/payment
