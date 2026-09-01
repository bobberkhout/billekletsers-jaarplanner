# Billekletsers Herinneringsmails 1.4.1

Losse WordPress-plugin voor deadlineherinneringen uit de Billekletsers Jaarplanner.

## Nieuw in 1.4.0

- Centrale projecten uit Jaarplanner 1.4.0 worden ondersteund.
- De einddatum van een project is de automatische herinneringsdatum.
- Projectleden die als WordPress-gebruiker aan het project zijn gekoppeld kunnen projectherinneringen ontvangen.
- Bij gekoppelde activiteiten, taken, inventarisaties en acties wordt de projectnaam in het beheeroverzicht en in de e-mail vermeld.
- Onder Instellingen kan **Projecten** afzonderlijk worden in- of uitgeschakeld; dit staat los van **Projectplanning**.

## Ontvangersvolgorde

1. Handmatig ingevulde **Ontvangers overschrijven**.
2. Gekoppelde WordPress-gebruikers.
3. Naam- en groepskoppelingen uit **Verantwoordelijke**.
4. De algemene fallbackontvanger, wanneer ingeschakeld.

## Ondersteunde onderdelen

- Projecten: gebruikt standaard de projecteinddatum.
- Jaarplanning/activiteiten: gebruikt de activiteitsdatum.
- Projectplanning: gebruikt een eventueel zelf ingestelde deadline.
- To-do: gebruikt de deadline.
- Inventarisaties: gebruikt de sluitdatum.
- Acties en besluiten: gebruikt de einddatum.

Een eigen deadline in de herinneringsplugin blijft altijd een expliciete override.

## Databehoud

De update importeert of wijzigt geen planningdata. Bestaande ontvangers, eigen deadlines, instellingen en verzendhistorie blijven behouden. De nieuwe instelling voor Projecten wordt bij bestaande installaties standaard ingeschakeld via de normale standaardinstellingen.

## Nieuw in 1.4.1

Projectplanning gebruikt nu ook het algemene deadlineveld uit de Jaarplanner. Een afzonderlijke handmatige herinneringsdeadline blijft, wanneer ingevuld, voorrang houden.
