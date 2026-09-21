# Stubs d'analyse statique

Ce répertoire contient les déclarations de classes et de fonctions fournies à l'exécution par
Restrict Content Pro et par le SDK Stripe embarqué, afin que PHPStan puisse analyser le plugin sans
disposer de ces dépendances.

Régénération :

```bash
# Depuis un WordPress provisionné (make up)
make shell
php -r "…" # ou générateur de stubs de votre choix
```

Les stubs ne sont **jamais** chargés à l'exécution.
