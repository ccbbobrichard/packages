composer create-project composer/satis --stability=dev --keep-vcs

php satis/bin/satis build satis.json dist/ --skip-errors


composer config --global --auth github-oauth.github.com <token>


php bin/satis build satis.json dist/ richard/payment