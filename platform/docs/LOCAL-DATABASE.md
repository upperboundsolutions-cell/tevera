# Local MariaDB databases

The local installation uses XAMPP's MariaDB service on `127.0.0.1:3306`.
Start **MySQL** in the XAMPP control panel before starting TEVERA or Traccar.

- `tevera`: PHP application data, accessed by its dedicated `tevera_local` database user.
- `traccar`: tracking data, accessed by its dedicated `traccar_local` database user.

Laravel uses `DB_CONNECTION=mysql` for MariaDB. Credentials are private in
`platform/.env` and `.tools/traccar-local.xml`; do not commit or publish them.
The Traccar JDBC connection applies its SQL mode compatibility setting only to
its own connections, without changing the server's global settings.

The previous SQLite and H2 files are retained. Pre-migration copies and original
configuration are in `.tools/before-mariadb*`. These contain private data and
must not be served publicly. They are snapshots, not ongoing backups.

Docker already uses separate MariaDB databases. Local databases are not copied
to Docker automatically. Back up and migrate them when deploying; use the
deployment backup workflow for ongoing production backups.
