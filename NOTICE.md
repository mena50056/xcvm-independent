# Attribution and status

This experimental distribution contains modified XC_VM source originally published
by Vateron Media at https://github.com/Vateron-Media/XC_VM under AGPL-3.0.
Original copyright and license notices remain in their respective files.
The independent runtime and deployment additions are distributed under AGPL-3.0.
Bundled third-party libraries and executables retain their respective licenses.
This project is not an official Vateron Media build and is not endorsed by it.

The original xcvm_core and ionCube extensions are excluded from release packages.
Local encryption keys and cluster identities are generated per installation.
No production database, activation key, customer record or server credential belongs
in this repository or its releases.

The release is experimental. Synthetic live TS/HLS and a same-host native cluster
node were tested in the lab. Clean-host installation, multi-host operation, VOD,
archive, RTMP, rollback, privileged node actions and production hardening require
separate validation. The bundled PHP built-in HTTP server is a lab transport.

Private repository visibility does not replace any applicable source-availability
obligations when modified software is distributed or offered to network users.
