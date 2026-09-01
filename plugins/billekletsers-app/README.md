# Billekletsers App 1.2.1

Losse WordPress-plugin die de bestaande Billekletsers Jaarplanner installeerbaar maakt als telefoonapp en app-updates beheert.

## Functionaliteit

- Web-appmanifest voor Apple- en Android-telefoons.
- Appiconen in meerdere formaten.
- Privacybewuste service worker zonder offline opslag van afgeschermde planningdata.
- Tijdelijke knop **Installeer op telefoon** in de bovenbalk.
- Vaste keuze **App installeren** onder **Account** voor ingelogde leden en onder **Opties** voor kijkers met het algemene wachtwoord.
- Native installatieprompt wanneer de browser deze aanbiedt.
- Duidelijke Apple- en Android-instructies als een native prompt niet beschikbaar is.
- De installatieknop verdwijnt automatisch wanneer de app vanaf het beginscherm draait.
- Beheerscherm via **Jaarplanner → Telefoonapp**.
- Veilige service-workerkoppeling voor de losse pushmeldingen-plugin.

## Nieuw in 1.2.1: installeren via Account of Opties

- **App installeren** is altijd terug te vinden in het uitklapmenu van de Jaarplanner.
- Android gebruikt waar mogelijk de directe installatieprompt.
- iPhone toont de stappen **Deel → Zet op beginscherm → Voeg toe**.
- In de reeds geïnstalleerde app wordt de installatiekeuze verborgen.

## App-updates sinds 1.2.0

- Een reeds geïnstalleerde app hoeft niet opnieuw geïnstalleerd te worden.
- Wanneer een nieuwe App-pluginversie klaarstaat verschijnt **Nieuwe versie beschikbaar**.
- Met **Nu bijwerken** wordt de wachtende service worker geactiveerd en de Jaarplanner eenmalig herladen.
- Ingelogde leden vinden onder **Account → App & updates**:
  - de huidige appversie;
  - het moment van de laatste controle;
  - **Controleren op updates**;
  - **Nu bijwerken** wanneer een versie klaarstaat.
- De app controleert automatisch bij openen en daarna minimaal iedere zes uur opnieuw.
- Persoonlijke login, planninggegevens en bestaande pushabonnementen blijven behouden.

## Databehoud

De plugin importeert, synchroniseert, verplaatst of wijzigt geen jaarplannerdata.

## Installatie of update

1. Upload de ZIP via **Plugins → Nieuwe plugin → Plugin uploaden**.
2. Kies voor het vervangen van de bestaande **Billekletsers App**.
3. Open de Jaarplanner eenmaal opnieuw.
4. Een reeds geïnstalleerde app neemt de nieuwe updatefunctie automatisch over.

De website moet via HTTPS bereikbaar zijn om als app geïnstalleerd en bijgewerkt te kunnen worden.
