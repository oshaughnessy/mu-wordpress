FROM wordpress:latest

# Give us a simple way to add our own settings to the WordPress config
RUN echo "include_once( dirname( __FILE__ ) . '/wp-config-local.php' );" >> /usr/src/wordpress/wp-config-sample.php

# Copy files from our local html directory to the WordPress html dir
COPY html /usr/src/wordpress/
