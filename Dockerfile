FROM wordpress

# Give us a simple way to add our own settings to the WordPress config
#RUN echo "include( dirname( __FILE__ ) . '/wp-config-local.php' );" >> /var/www/html/wp-config.php
#RUN echo ----
#RUN echo /var/www/html/wp-config.php
#RUN tail -20 /var/www/html/wp-config.php
#RUN echo ----

# Copy files from our local html directory to the WordPress html dir
#RUN echo Installing local "html" content
#COPY html /var/www/html/
#RUN echo ----
