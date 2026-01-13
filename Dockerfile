# Utilisation de Debian 12 (Bookworm) version légère
FROM debian:bookworm-slim

# Éviter les questions lors de l'installation
ENV DEBIAN_FRONTEND=noninteractive

# 1. Installation des paquets nécessaires
RUN apt-get update && apt-get install -y \
    nginx \
    php-fpm \
    php-mysql \
    mariadb-server \
    supervisor \
	wget \
	unzip \
    && rm -rf /var/lib/apt/lists/*

# 2. Configuration de PHP-FPM (Création du dossier pour le socket)
RUN mkdir -p /run/php && \
    chown -R www-data:www-data /run/php

# 3. Configuration de MariaDB (Dossiers nécessaires)
RUN mkdir -p /var/run/mysqld && \
    chown -R mysql:mysql /var/run/mysqld && \
    chmod 777 /var/run/mysqld
	
# --- ETAPE CRITIQUE : Installation de phpMyAdmin ---
# On copie SEULEMENT le zip d'abord (pour profiter du cache Docker)
COPY phpmyadmin.zip /var/www/phpmyadmin.zip

# On dézippe, on renforce le dossier correctement, et on nettoie
RUN unzip /var/www/phpmyadmin.zip -d /var/www/ \
    && mv /var/www/phpMyAdmin-*-all-languages /var/www//html/phpmyadmin \
    && rm /var/www/phpmyadmin.zip

# 4. Copie des fichiers de configuration (qu'on va créer plus bas)
COPY nginx.conf /etc/nginx/sites-available/default
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY entrypoint.sh /entrypoint.sh

# 5. Copie du code source de l'application
COPY . /var/www/html/

# 6. Permissions pour le serveur web
RUN chown -R www-data:www-data /var/www/

# 7. Rendre le script de démarrage exécutable
RUN chmod +x /entrypoint.sh

# 8. Exposition du port 80
EXPOSE 80

# 9. Commande de démarrage
CMD ["/entrypoint.sh"]