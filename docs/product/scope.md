# Product Scope C0-C2

FamilyPlaces helps a parent or carer discover age-appropriate places in a city.
The first vertical slice supplies a curated demonstration catalogue, explicit
age ranges, amenities, opening hours, distance search, a bounded map view, and
an authenticated administration panel.

## Actors and outcomes

- Visitor: searches published places by city, category, age, location, amenity,
  venue characteristics, opening state, and text; reads accessible SSR results.
- Administrator: curates dictionaries and places and moves complete places
  through an explicit publication workflow.
- Operator: starts the stack, applies deterministic migrations and fixtures,
  checks health, and receives structured production logs.

All seeded places are clearly named as demonstrations and never presented as
real businesses. The catalogue is local data; no external place provider is
called in C0-C2.
