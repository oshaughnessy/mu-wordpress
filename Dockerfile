FROM wordpress

# Give us a simple way to add our own settings to the WordPress config
RUN true \
  && echo "include( dirname( __FILE__ ) . '/wp-config-local.php' );" >> /var/www/html/wp-config.php \
  && tail -20 /var/www/html/wp-config.php

# Copy files from our local html directory to the WordPress html dir
COPY html /var/www/html/
