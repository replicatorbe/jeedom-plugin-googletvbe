# Plugin Google TV

Pilote en réseau local les téléviseurs **Google TV / Android TV** (TCL, Sony,
Philips, Hisense, Chromecast avec Google TV…), en PHP natif : ni cloud, ni
Python, ni `adb` à installer.

## Widget : une télécommande

Sur le dashboard, la TV prend la forme d'une télécommande, mise à jour en
direct :

- un petit écran : allumée, en veille ou hors ligne, volume, appli en cours
  et ce qui est lu ;
- marche/arrêt (allume une TV en veille, éteint une TV allumée), source,
  paramètres ;
- croix directionnelle et OK, Retour, Accueil, Menu ;
- précédent, retour rapide, lecture/pause, avance rapide, suivant ;
- volume (+, −, muet, curseur) et chaînes ;
- les applis de « Lancer une application », d'un clic.

Les flèches et le volume se répètent tant qu'on reste appuyé. Les touches
demandent l'appairage de la télécommande : sans lui, elles sont estompées ;
le volume, qui passe par Cast, reste utilisable. Le widget garde sa taille
naturelle (270 px de large).

## Ce que fait le plugin

Le démon garde avec chaque TV une connexion **Google Cast** ouverte (port
8009). La TV y signale d'elle-même chaque changement ; aucune interrogation
périodique n'est faite.

| Commande | Type | Rôle |
|---|---|---|
| En ligne | info | Le démon joint la TV (Cast) |
| Allumée | info | L'écran est allumé (la TV n'est pas en veille). Repasse à 0 quand la TV disparaît du réseau |
| Entrée active | info | La TV affiche sa propre source (masquée par défaut) |
| Volume | info | Volume en % |
| Muet | info | Son coupé |
| Application Cast, ID application Cast | info | Application en cours vue par Cast (YouTube, Spotify, et aussi Netflix sur une TCL) |
| Statut Cast | info | Texte affiché par l'application Cast |
| État de lecture | info | `lecture`, `pause`, `chargement`, `arrêt` |
| Titre, Artiste - série | info | Ce qui est lu en Cast |
| Rafraîchir | action | Redemande l'état à la TV |
| Allumer (réveil réseau) | action | Wake-on-LAN |
| Régler le volume | action | Curseur 0 à 100 % |
| Volume + / Volume - | action | Pas réglable dans la configuration |
| Couper le son / Rétablir le son / Muet on-off | action | |
| Lire, Pause, Lecture-pause, Stop, Précédent, Suivant | action | Lecture Cast en cours, sinon touche média (voir plus bas) |
| Lancer une application Cast | action | Liste : YouTube, Netflix, Spotify, Plex |
| Lancer par ID Cast | action | Identifiant Cast en message (8 caractères hexadécimaux) |
| Quitter l’application Cast | action | |

## Télécommande (appairage)

Une fois la TV **appairée**, le plugin parle aussi le protocole de l'appli
Google TV (ports 6466 et 6467) :

| Commande | Type | Rôle |
|---|---|---|
| Télécommande connectée | info | La connexion de commande est ouverte |
| Application | info | Paquet Android au premier plan (`com.netflix.ninja`…) |
| Allumer / Éteindre / Marche-arrêt | action | Touche Marche, envoyée seulement si l'état diffère ; « Allumer » passe au réveil réseau si la TV ne répond plus |
| Haut, Bas, Gauche, Droite, OK, Retour, Accueil, Menu, Paramètres, Guide, Info | action | Touches |
| Chaîne + / Chaîne -, Source, HDMI 1 à 4 | action | |
| Retour rapide / Avance rapide | action | |
| Lancer une application | action | Liste : YouTube, Netflix, Prime Video, Disney+, Spotify, Plex |
| Ouvrir un lien ou un paquet | action | Un lien (`https://…`, `spotify://`) ou un nom de paquet Android (`org.xbmc.kodi`) : voir ci-dessous |
| Touche | action | Un nom (`DPAD_UP`, `KEYCODE_HOME`, `MEDIA_PLAY_PAUSE`), un chiffre seul (touche chiffre), ou un code KeyEvent Android de 10 ou plus |

**Ouvrir une appli par son paquet.** La TV n'ouvre que les liens qu'une appli
déclare savoir traiter ; elle refuse `market://launch?id=…`, `intent:…` et
`package:…` (et coupe la connexion une seconde). Pour un nom de paquet, le
plugin ouvre donc la fiche Play Store de l'appli, puis appuie sur OK : le
bouton « Ouvrir » y est sélectionné d'office. Comptez quatre ou cinq secondes.
**Attention : si l'appli n'est pas installée, le bouton sélectionné est
« Installer », et OK l'installe.** Ne donnez que des paquets d'applis
installées ; préférez un lien quand l'appli en accepte un.

Le démon transmet un lien sans savoir si la TV l'accepte : un lien refusé
(appli absente, schéma inconnu) n'est signalé que dans le journal
`googletvbed`.

Lire, Pause, Stop, Précédent et Suivant agissent sur la lecture Cast quand il
y en a une, et sinon envoient la touche média à l'appli au premier plan.

**Appairer :** TV allumée, ouvrez l'équipement et cliquez sur *Appairer la
télécommande*. La TV affiche un code de six caractères : recopiez-le (un code
mal recopié est redemandé ; *Annuler* abandonne sans toucher à un appairage
existant). C'est fait une fois pour toutes. Le certificat de Jeedom est gardé dans la
configuration du plugin ; si la TV l'oublie (réinitialisation), l'état
l'indique et il suffit d'appairer de nouveau.

Si l'appairage échoue sans cesse : sur la TV, *Paramètres → Applications →
Applications système → Android TV Remote Service → Vider le stockage*.

## Installation

1. Activez le plugin, puis démarrez le démon (aucune dépendance à installer).
2. **Rechercher des TV** interroge le réseau (SSDP) ; **Ajouter par adresse
   IP** sert quand le multicast ne passe pas.
3. Créez la TV : les commandes se remplissent dès que le démon la joint.

## TvOverlay : garder l'appli en marche

Les notifications par-dessus l'image passent par l'appli **TvOverlay** et le
plugin Jeedom **TvOverlay** (`tvoverlaybe`). Ce plugin-ci la garde en marche :

| Commande | Rôle |
|---|---|
| TvOverlay actif (info) | L'appli répond |
| Relancer TvOverlay | Relance immédiate |

**Android arrête TvOverlay de temps à autre.** Le plugin la surveille chaque
minute quand la TV est allumée et la relance par la télécommande (appairage
requis, pas d'ADB) : fiche Play Store, OK sur « Ouvrir », puis deux fois
Retour, qui rendent l'image d'avant, et — option « Reprendre la lecture »,
cochée par défaut — la touche Lecture, qui relance le film que Netflix a mis
en pause entre-temps (un film mis en pause volontairement reprend aussi).
Une vingtaine de secondes, en tâche de fond.

Par défaut, cette relance automatique n'a lieu que **dans les 3 minutes qui
suivent l'allumage**, quand l'écran d'accueil est affiché : elle n'interrompt
jamais un film en cours. Le réglage « À tout moment » la permet en
permanence, « Jamais » la supprime. Indépendamment de ce réglage, une
notification du plugin TvOverlay qui trouve l'appli arrêtée, TV allumée, la
fait relancer, puis part. Une relance n'est jamais refaite moins de deux
minutes après la précédente : deux séquences mêlées feraient sortir de
l'appli regardée. Pour qu'Android la laisse tranquille, l'exempter
une fois de l'optimisation de batterie :
`adb shell dumpsys deviceidle whitelist +com.tabdeveloper.tvoverlay`.

## Allumage par le réseau

Depuis la veille, « Allumer » passe par la télécommande. Une TV **éteinte**
n'écoute plus le réseau, sauf si elle le garde en veille :

- sur une TCL : *Paramètres → Système → Alimentation et énergie →
  Veille en réseau* (ou *Mise en marche via le réseau / Wi-Fi*) ;
- renseignez les **adresses MAC** de la TV. Wi-Fi et Ethernet en ont chacune
  une ; celle qui répond est relevée automatiquement à la création, ajoutez
  l'autre à la main.

## Diagnostic

- L'onglet **Diagnostic** montre le dernier état brut reçu de la TV.
- Le journal `googletvbed` (niveau *Debug*) montre chaque message échangé.
- Le démon écoute les ordres de Jeedom sur `127.0.0.1:55180`, en boucle locale
  uniquement ; le port se change dans la configuration.
