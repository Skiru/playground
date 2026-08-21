FROM postgres:18-bookworm@sha256:7d2695c3aa88e792e8b3b233e7e4adb296a20412c6c0ca361e3edaaacfada108

ARG TARGETARCH
ARG AWS_CLI_VERSION=2.27.41
ARG AWS_CLI_ARM64_SHA256=2c6ed21cf7cff0a7d77118c69bee867128bf4c588db7b5c044ffba5faeb6ccde
ARG AWS_CLI_AMD64_SHA256=15daae6cc803984064e3d4be9cfd07c4ae8ea703633c0a0b67acc6e321f706a3

RUN apt-get update \
    && apt-get install -y --no-install-recommends age ca-certificates curl jq openssl unzip util-linux bash \
    && case "$TARGETARCH" in \
         arm64) aws_arch=aarch64; aws_sha="$AWS_CLI_ARM64_SHA256" ;; \
         amd64) aws_arch=x86_64; aws_sha="$AWS_CLI_AMD64_SHA256" ;; \
         *) echo "unsupported certification tools architecture: $TARGETARCH" >&2; exit 1 ;; \
       esac \
    && curl --fail --silent --show-error --location \
         "https://awscli.amazonaws.com/awscli-exe-linux-${aws_arch}-${AWS_CLI_VERSION}.zip" \
         -o /tmp/awscliv2.zip \
    && echo "$aws_sha  /tmp/awscliv2.zip" | sha256sum --check --strict \
    && unzip -q /tmp/awscliv2.zip -d /tmp \
    && /tmp/aws/install \
    && rm -rf /tmp/aws /tmp/awscliv2.zip /var/lib/apt/lists/*

WORKDIR /workspace
ENTRYPOINT []
CMD ["bash"]
