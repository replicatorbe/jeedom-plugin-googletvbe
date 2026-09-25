# Changelog

## 0.2.0

- Widget en forme de télécommande : écran d'état, croix directionnelle,
  lecture, volume, chaînes, applis, mise à jour en direct.
- TvOverlay : surveillance et relance de l'appli par la télécommande (fiche
  Play Store, Ouvrir, Retour, Lecture), dans les minutes qui suivent
  l'allumage par défaut, en tâche de fond, jamais deux fois en moins de deux
  minutes. Les notifications passent au plugin TvOverlay.
- Télécommande Android TV : appairage par code affiché sur la TV, touches,
  marche/arrêt, sources HDMI, lancement d'applis par lien ou paquet, appli au
  premier plan.
- Lecture, pause, stop, suivant, précédent : touche média de la télécommande
  quand rien n'est lu en Cast (Netflix, Disney+…).
- Recherche : les TV cochées sont de nouveau créées (la fenêtre de
  confirmation perdait les cases avant de les lire).
- Démon : envoi des valeurs à Jeedom sans bloquer ; état remis à « éteinte »
  quand la TV disparaît du réseau ; une erreur imprévue n'arrête plus le
  démon.
- Noms de commandes sans « / » ni « ' », que le coeur retirait
  (« Marchearrêt ») : les noms abîmés sont corrigés à la mise à jour.
- « Lancer une application » ne propose plus de nom de paquet : sur la fiche
  Play Store, OK installerait une appli absente.

## 0.1.0

- Première version : connexion Google Cast persistante (volume, muet, veille,
  application Cast, lecture), recherche SSDP, réveil par le réseau.
