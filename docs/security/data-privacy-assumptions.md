# Data Privacy Assumptions

C0-C2 stores public place facts and administrator credentials. It stores no child
profiles, precise user location history, favourites, visits, reviews, private
messages, or uploads. Search coordinates are transient request inputs and are not
included in application logs. Demonstration fixtures contain no real business or
personal data.

Admin accounts use individual password hashes and server-side sessions. A local
admin creation command is restricted to dev/test and receives or generates a
non-shared password. Production secrets arrive via environment or deployment
secret facilities and are absent from Git.

Future user/location features require a new privacy review covering purpose,
retention, access, deletion, consent, and abuse cases before implementation.
