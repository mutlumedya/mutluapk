RewriteEngine On
RewriteBase /film/

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ bot.php [QSA,L]
