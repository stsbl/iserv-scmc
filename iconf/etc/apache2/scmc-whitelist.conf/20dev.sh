#!/bin/sh

. /usr/lib/iserv/cfg

if [ "$DevEnvironment" ]
then
  echo
  echo "  RewriteCond %{REQUEST_URI} ^/_profiler [OR]"
  echo "  RewriteCond %{REQUEST_URI} ^/_wdt"
  echo "  RewriteRule (.*) /usr/share/iserv/web/public/app_dev.php [L]"
  echo
fi
