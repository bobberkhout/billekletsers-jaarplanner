# Billekletsers Billeplein 1.0.0

Interne sociale module voor de planningwebsite. Alleen persoonlijk ingelogde WordPress-gebruikers zien het Billeplein; bezoekers met alleen het algemene kijkwachtwoord niet.

## Functies
- Vraag, Idee, Hulp gezocht en Poll.
- Koppeling aan een bestaand Jaarplanner-project of Algemeen.
- Reacties, duimpjes en bij hulpvragen een `Ik help mee`-reactie.
- Vraag kan door auteur/beheerder als Beantwoord worden gemarkeerd; hulpvraag als Afgerond.
- Polls ondersteunen één of meerdere keuzes en een optionele sluitdatum. Een lid kan zijn stem later wijzigen.
- Filters op type, project en zoektekst.
- Overzichtsblok met `Stel een vraag`, `Deel een idee`, `Hulp gezocht` en `Maak een poll` plus recente berichten/open vragen/open polls.
- E-mail bij nieuw bericht/poll en bij reactie op een eigen bericht. Ieder lid kan beide meldingen zelf uitschakelen.
- Beheerder kan globale e-mailmeldingen aan/uit zetten via Billeplein > Instellingen.

## Vereist
Voor de integratie op het overzicht en in de navigatie is Billekletsers Jaarplanner 1.5.5 of nieuwer nodig.

## Data
De plugin maakt een eigen WordPress post type `bkb_post` en gebruikt WordPress-comments voor reacties. Installatie wijzigt geen bestaande Jaarplanner-data.
