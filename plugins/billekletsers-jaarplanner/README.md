# Billekletsers Jaarplanner 1.5.4

Deze versie breidt **Nieuw seizoen klaarzetten** uit met een veilige status-reset voor alle onderdelen die als afgerond zijn gemarkeerd. De reset gebeurt uitsluitend tijdens een bevestigde seizoenswissel en staat in het controlescherm standaard aangevinkt.

- terugkerende projecten: `Afgerond` → `Voorbereiding`;
- projectplanning: `Afgerond` → `Nog in te plannen`;
- inventarisaties: `Afgerond` → `Voorbereiding` (en blijven bij de seizoenswissel gesloten totdat ze weer worden geopend);
- to-do: `Afgerond` → `Open`;
- acties & besluiten: `Afgerond` → `Open`;
- legacy-termen zoals `Af`, `Gereed`, `Klaar`, `Done` en `Voltooid` worden eveneens als afgerond herkend;
- `Geannuleerd` en `Gearchiveerd` worden bewust niet opnieuw geopend;
- activiteiten worden niet aangepast, omdat `Vastgesteld` geen afgerond-status is.

De automatische seizoensback-up bewaart vanaf deze versie ook `_bkp_task_status` en `_bkp_done`, zodat herstel de oude statussen exact terugzet. Installatie/activatie van de plugin wijzigt geen bestaande planningdata.

# Billekletsers Jaarplanner 1.5.1

Deze versie herstelt de mobiele styling naar de weergave van 1.4.3. Geen datawijzigingen.

# Billekletsers Jaarplanner 1.4.3

Centrale WordPress-plugin voor de interne jaarplanning van C.V. De Billekletsers.

Getest op PHP-syntaxis voor de opgegeven omgeving WordPress 7.0.2 en PHP 8.3. De plugin vereist minimaal WordPress 6.4 en PHP 7.4.

## Belangrijk: geen automatische dataverwerking

Deze update:

- importeert geen Excelbestand;
- maakt geen projecten of projectkoppelingen automatisch aan;
- wijzigt geen bestaande planningregels, datums, namen of verantwoordelijken bij installatie;
- verwijdert het oude veld `Hoofdonderwerp` niet;
- past gegevens alleen aan nadat een bevoegde gebruiker zelf opslaat of een gecontroleerde seizoenswissel bevestigt.

## Projecten als centrale kapstok

Nieuw posttype `bkp_project` met per project:

- projectnaam;
- seizoen;
- status;
- start- en einddatum;
- projectleider/verantwoordelijke;
- meerdere gekoppelde WordPress-gebruikers;
- terugkerend project ja/nee;
- omschrijving.

Aan de volgende onderdelen kan een project worden gekoppeld:

- activiteiten in de jaarplanning;
- projectplanningstaken;
- inventarisaties;
- to-do's;
- acties en besluiten;
- documenten.

De koppeling wordt opgeslagen in `_bkp_project_id`. Bestaande gegevens zonder koppeling blijven volledig bruikbaar en worden als **Niet gekoppeld** getoond.

## Frontend

Nieuw hoofdonderdeel **Projecten** met per project:

- voortgangspercentage;
- aantal open en verlopen werkonderdelen;
- status, looptijd, projectleider en projectleden;
- gekoppelde activiteiten, projectplanning, to-do's, inventarisaties, acties en documenten.

Op het overzicht staan projectaantallen en een compact projectstatusoverzicht. In tabellen en kaarten wordt de projectnaam zichtbaar. **Mijn verantwoordelijkheden** groepeert de persoonlijke taken per project.

## Backend en frontend bewerken

Onder **Jaarplanner → Projecten** kunnen projecten via dezelfde snelle gridwerkwijze worden beheerd. Alle relevante grids en frontend-editors hebben een projectkeuze gekregen.

Een project waaraan nog onderdelen of documenten gekoppeld zijn, wordt niet stilzwijgend verwijderd. Koppel die onderdelen eerst om.

Bewerkrechten voor Projecten zijn afzonderlijk toe te kennen onder **Jaarplanner → Gebruikers & rechten**.

## Gecontroleerde koppelassistent

Onder Projecten staat een koppelassistent voor bestaande to-do's met het oude veld **Hoofdonderwerp**.

- Er gebeurt niets zonder selectie en bevestiging.
- Per oud hoofdonderwerp kan een bestaand project worden gekozen of een nieuw project worden aangemaakt.
- Alleen nog ongekoppelde to-do's worden gekoppeld.
- Het oude hoofdonderwerp blijft als naslag bewaard.

Activiteiten, projectplanning, inventarisaties, acties en documenten worden bewust niet automatisch gekoppeld; die koppeling wordt door jullie zelf gecontroleerd ingevuld.

## Nieuw seizoen

Bij **Nieuw seizoen klaarzetten** kunnen terugkerende projecten worden meegenomen:

- projectkoppelingen, projectleider en projectleden blijven behouden;
- alleen projecten met **Terugkerend** aangevinkt krijgen het nieuwe seizoen;
- start- en einddatum staan als controleerbare regels in het voorstel;
- afgeronde projectstatussen kunnen optioneel worden teruggezet naar **Voorbereiding**;
- seizoensback-ups bevatten ook projectgegevens en de verzendhistorie van e-mail- en pushherinneringen.

## Download

De volledige jaarplanningdownload bevat nu ook:

- de projectenlijst;
- een projectkolom bij alle gekoppelde onderdelen;
- projectkoppelingen van documenten.

## Technische opslag

De bestaande opslag blijft ongewijzigd voor:

- `bkp_event`, `bkp_task`, `bkp_inventory` en `bkp_action`;
- bestaande `bkp_*` opties en metadata;
- documenten in de beveiligde uploadmap;
- inventarisatiereacties en gebruikersrechten.

Nieuw is uitsluitend het posttype `bkp_project` en de optionele metadata `_bkp_project_id` bij gekoppelde onderdelen.

## Versie 1.4.2 – detail vanuit Mijn verantwoordelijkheden

Een gekoppelde gebruiker kan een item in **Mijn verantwoordelijkheden** openen. De detailpagina toont project, onderdeel, verantwoordelijken en algemene notities. De gekoppelde gebruiker kan voor dit ene item de operationele datum/deadline en status bijwerken en voortgangsnotities of notulen toevoegen. Iedere logboekregel krijgt automatisch de naam, datum en tijd van de gebruiker. Dit geeft geen brede bewerkrechten voor de rest van het onderdeel.

Projectplanning heeft nu ook een eigen deadlineveld in de backend- en frontendgrid. Deze deadline wordt meegenomen in de volledige planningdownload en in het voorstel voor een nieuw seizoen. Er wordt geen bestaande data geïmporteerd of automatisch aangepast.


## Statuskeuzes 1.4.2

Statusvelden zijn niet langer vrije tekstvelden. In de WordPress-backend, frontend-editor en het detailscherm van **Mijn verantwoordelijkheden** worden vaste keuzelijsten gebruikt voor Projecten, Projectplanning, Inventarisaties, To-do en Acties & besluiten. Bestaande afwijkende statusteksten blijven als legacy-keuze zichtbaar totdat iemand bewust een standaardstatus kiest. De update wijzigt bij installatie geen bestaande gegevens.


## Mobiele submenu's 1.4.3

Op telefoons openen **Werk & taken**, **Vereniging**, **Account** en **Opties** voortaan als een duidelijke bodemlade boven de volledige pagina. De inhoud wordt tijdelijk buiten de sticky navigatie geplaatst, zodat iPhone Safari, Android Chrome en de geïnstalleerde webapp het menu niet meer kunnen afknippen of onder andere onderdelen tonen. De lade heeft een donkere achtergrondlaag, een eigen sluitknop, ondersteuning voor de terug/escape-interactie en veilige ruimte onderaan voor iPhones.

Deze update wijzigt uitsluitend frontend-CSS en JavaScript. Er wordt geen planningdata geïmporteerd, aangepast, verwijderd of opnieuw opgeslagen.


## 1.5.0 - koppeling openbare website
- Activiteiten hebben een vinkje `Website` / `Op openbare website tonen`.
- Een apart veld `Website tekst` voorkomt dat interne notities openbaar worden.
- Alleen expliciet aangevinkte activiteiten zijn beschikbaar via de read-only REST-koppeling.
- Endpoint: `/wp-json/billekletsers/v1/activiteiten`.
- Er wordt geen bestaande planningdata automatisch gepubliceerd of aangepast.


## 1.5.1
- Herstelt de openbare REST-koppeling voor `/wp-json/billekletsers/v1/activiteiten` en `/status`.
- Alle overige REST-routes van de afgeschermde Jaarplanner blijven zonder toegang een HTTP 401 geven.
- Geen planningdata wordt geimporteerd of gewijzigd.


## Versie 1.5.3 – filters bij Mijn verantwoordelijkheden

**Mijn verantwoordelijkheden** heeft nu filters voor zoeken, project, onderdeel en termijn. De bestaande groepering per project blijft behouden. Project- en groepsaantallen worden tijdens het filteren live bijgewerkt en er is een knop om alle filters te wissen. Deze update wijzigt uitsluitend de weergave en frontend-filtering; bestaande planningdata wordt niet geïmporteerd, aangepast of verwijderd.


## Versie 1.5.4 – vereenvoudigd menu voor algemeen kijkwachtwoord

- Bezoekers die alleen via het algemene kijkwachtwoord binnenkomen zien in de hoofdnavigatie alleen **Overzicht**, **Jaarplanning** en **Vereniging**.
- Onder **Vereniging** blijven Commissies, Documenten en Bestuur & contact beschikbaar.
- Persoonlijk ingelogde WordPress-gebruikers houden Projecten, Mijn verantwoordelijkheden en Werk & taken in het menu.
- Deze wijziging verandert geen planningdata of rechten; het is uitsluitend een vereenvoudiging van de navigatie voor meekijkers.


## Versie 1.5.5 – uitbreidingspunten voor persoonlijke modules

De frontend bevat nu veilige WordPress-hooks voor persoonlijke navigatie-items, extra blokken op het overzicht en extra panelen. Hierdoor kan de losse Billeplein-plugin integreren zonder planningdata te wijzigen of de sociale functionaliteit in de Jaarplanner zelf op te nemen. Zonder uitbreidingsplugin verandert de zichtbare Jaarplanner niet.
