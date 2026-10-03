# Garage (S3-compatible object storage, https://garagehq.deuxfleurs.fr)
# for local development, replacing MinIO (no longer published). The
# official image has no shell; this one adds one, for the init script.
FROM dxflrs/garage:v2.1.0 AS garage

FROM alpine:3.20
COPY --from=garage /garage /usr/local/bin/garage
COPY garage.toml /etc/garage.toml
COPY init.sh /usr/local/bin/garage-init
RUN chmod +x /usr/local/bin/garage-init
# The server, then the one-shot setup next to it (the CLI talks to the
# local RPC port, so both run in the same container).
CMD ["sh", "-c", "garage -c /etc/garage.toml server & garage-init && wait"]
