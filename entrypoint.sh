#!/bin/bash

# Démarrage de MariaDB en arrière-plan
echo "➡️  Démarrage temporaire de MariaDB..."
mysqld_safe --skip-networking &
pid="$!"

# Attendre que MariaDB soit vraiment prêt (boucle de vérification)
echo "➡️  Attente disponibilité MariaDB..."
for i in {1..30}; do
    if mariadb-admin ping --silent; then
        break
    fi
    sleep 1
done

echo "➡️  Configuration de la Base de Données..."

# Configuration SQL
mariadb -e "CREATE DATABASE IF NOT EXISTS db_coop;"
mariadb -e "CREATE USER IF NOT EXISTS 'admin'@'%' IDENTIFIED BY 'admin';"
mariadb -e "GRANT ALL PRIVILEGES ON *.* TO 'admin'@'%' WITH GRANT OPTION;"
mariadb -e "FLUSH PRIVILEGES;"

# Import du fichier SQL
if [ -f /var/www/html/coop_db.sql ]; then
    echo "➡️  Importation du fichier coop_db.sql..."
    # On utilise le mot de passe root qu'on vient de définir pour être sûr
    mariadb -u root -proot db_coop < /var/www/html/coop_db.sql
    echo "✅  Import terminé."
else
    echo "⚠️  Fichier coop_db.sql non trouvé à la racine !"
fi

# ARRÊT PROPRE (C'est ici que ça bloquait)
echo "➡️  Arrêt du processus temporaire..."
mariadb-admin -u root -proot shutdown

# On attend que le processus s'arrête vraiment
wait "$pid"

echo "✅  Initialisation terminée. Lancement de Supervisor..."

# Lancement final du serveur Web + BDD
exec /usr/bin/supervisord