# check=skip=InvalidDefaultArgInFrom
# Bootgly CI — the image of one container cell of the test matrix: the cell's
# base image provisioned by install.sh. Built per job by
# .github/workflows/bootgly.yml; the suite then runs in it as the runner's own
# uid/gid, with an init as PID 1 (`docker run --init`).
#
# Reproduce a cell locally, from the root of a clone (BASE and DISTRO: the
# matrix's `image` and `os`; ubuntu-24.04/ubuntu-26.04 + their ubuntu image
# stand in for the runner cells):
#    docker build --file .github/scripts/cell.Dockerfile \
#       --build-arg BASE=public.ecr.aws/docker/library/debian:13 \
#       --build-arg DISTRO=debian-13 --build-arg PHP=8.5 \
#       --build-arg CI_UID="$(id -u)" --build-arg CI_GID="$(id -g)" \
#       --tag bootgly-matrix-cell .github/scripts
#    docker run --rm --init --user "$(id -u):$(id -g)" \
#       --sysctl net.ipv6.conf.lo.disable_ipv6=0 --ulimit nofile=65536:65536 \
#       --env BOOTGLY_CI_PHP=8.5 --volume "$PWD:/home/runner/work/bootgly/bootgly" \
#       --workdir /home/runner/work/bootgly/bootgly bootgly-matrix-cell bash .github/scripts/test.sh
# ! Mount the clone at a short path like the runner's: the suite's unix
#   sockets under storage/pids/ must fit in sun_path (108 bytes).
ARG BASE
FROM ${BASE}

ARG DISTRO
ARG PHP
ARG CI_UID=1000
ARG CI_GID=1000

COPY install.sh /usr/local/share/bootgly-ci/install.sh
RUN BOOTGLY_CI_UID="${CI_UID}" BOOTGLY_CI_GID="${CI_GID}" \
    sh /usr/local/share/bootgly-ci/install.sh "${DISTRO}" "${PHP}"
