#!/bin/bash
# Runs inside the linuxserver/transmission container before Transmission starts.
# The image makes the web interface listen on IPv6 ("[::]"); the network of the VPN container
# has IPv6 turned off, so there it has to listen on IPv4.
sed -i 's/"rpc-bind-address": *"[^"]*"/"rpc-bind-address": "0.0.0.0"/' /config/settings.json
