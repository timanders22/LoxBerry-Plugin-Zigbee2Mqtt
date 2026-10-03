#!/bin/bash

# Bashscript which is executed by bash *AFTER* complete installation is done
# (*AFTER* postinstall but *BEFORE* postupdate). Use with caution and remember,
# that all systems may be different!
#
# Exit code must be 0 if executed successfull.
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
# Will be executed as user "root".
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0  # Zero argument is shell command
PTEMPDIR=$1 # First argument is temp folder during install
PSHNAME=$2  # Second argument is Plugin-Name for scipts etc.
PDIR=$3     # Third argument is Plugin installation folder
PVERSION=$4 # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
PTEMPPATH=$6  # Sixth argument is full temp path during install (see also $1)

# Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

echo "<INFO> Command is: $COMMAND"
echo "<INFO> Temporary folder is: $PTEMPDIR"
echo "<INFO> (Short) Name is: $PSHNAME"
echo "<INFO> Loxberry Home is: $LBHOMEDIR"
echo "<INFO> Plugin installation folder is: $PDIR"

#source version file
. ${PTEMPPATH}/version.sh

# Zigbee2MqttNG installs into its own folder and runs its own service, so it never
# collides with the original Zigbee2Mqtt plugin (/opt/zigbee2mqtt, zigbee2mqtt.service)
# or with Zigbee2Lox, the former name of this plugin (/opt/zigbee2lox, zigbee2lox.service).
INSTALLDIR=/opt/zigbee2mqttng
SERVICE=zigbee2mqttng
# Folders (= service names) of the predecessor plugins whose network is taken
# over once on a fresh install. The first one found wins: Zigbee2Lox already
# took over the original, so it holds the newest data.
PREDECESSORS="zigbee2lox zigbee2mqtt"


ISUPGRADE=0
if [ -d "/tmp/${PTEMPDIR}_upgrade" ]; then
    echo "<INFO> Upgrade detected"
    ISUPGRADE=1

    #Replace service config in backup because it is copied back in the next step
    if [ -d "$LBHOMEDIR/config/plugins/$PDIR" ]; then
        cp -f -r $LBHOMEDIR/config/plugins/$PDIR/*.service /tmp/${PTEMPDIR}_upgrade/config/$PDIR/
    fi

    echo "<INFO> Copy back existing config files"
    if [ -d "/tmp/${PTEMPDIR}_upgrade/config/$PDIR" ]; then
        cp -f -r /tmp/${PTEMPDIR}_upgrade/config/$PDIR/* $LBHOMEDIR/config/plugins/$PDIR/
    fi
    if [ -d "/tmp/${PTEMPDIR}_upgrade/data/$PDIR" ]; then
        cp -f -r /tmp/${PTEMPDIR}_upgrade/data/$PDIR/* $LBHOMEDIR/data/plugins/$PDIR/
    fi
fi

if [ -e $INSTALLDIR ]; then
    echo "<INFO> Removing old zigbee2mqtt installation"
    rm -f -r $INSTALLDIR
fi

git clone --branch $ZIGBEE2MQTT_VERSION --depth 1 https://github.com/Koenkk/zigbee2mqtt.git $INSTALLDIR

cd $INSTALLDIR

# Get system architecture
ARCH=$(uname -m)

# Map architecture to Node.js download URL
case $ARCH in
  x86_64)
    NODE_ARCH="x64"
    ;;
  aarch64)
    NODE_ARCH="arm64"
    ;;
  armv7l)
    NODE_ARCH="armv7l"
    ;;
  *)
    echo "Unsupported architecture: $ARCH"
    exit 1
    ;;
esac

# NODE_VERSION is set in version.sh

wget https://nodejs.org/dist/$NODE_VERSION/node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz
tar -xvf node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz
mkdir -p $INSTALLDIR/node
mv node-$NODE_VERSION-linux-$NODE_ARCH/* $INSTALLDIR/node/
rm -rf node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz
export PATH=$INSTALLDIR/node/bin:$PATH


npm install -g "$(node -p "require('./package.json').packageManager")"
node --version  
pnpm --version  
pnpm i --frozen-lockfile

# Build Zigbee2MQTT
pnpm run build
retval="$?"
if [ $retval -ne 0 ]; then
    echo "npm install failed"
    exit $retval
fi

echo "<INFO> Remove default data folder"
rm -f -r $INSTALLDIR/data

chown -R loxberry:loxberry $INSTALLDIR

echo "<INFO> Remove temporary folders"
rm -f -r /tmp/${PTEMPDIR}_upgrade

echo "<INFO> Linking log to log folder"
ln -f -s $PLOG $INSTALLDIR/log

echo "<INFO> Updating data folder"
ln -f -s $PDATA $INSTALLDIR/data

# Fresh installation next to (or instead of) a predecessor plugin: take over
# its network so no device has to be paired again.
MIGRATED=0
for ORIGDIR in $PREDECESSORS; do
    ORIGSERVICE=$ORIGDIR
    ORIGDATA=$LBHOMEDIR/data/plugins/$ORIGDIR
    ORIGCONFIG=$LBHOMEDIR/config/plugins/$ORIGDIR
    if [ "$ISUPGRADE" -ne "0" ] || [ "$MIGRATED" -ne "0" ] || [ "$PDIR" = "$ORIGDIR" ]; then
        continue
    fi
    if [ ! -f "$ORIGDATA/configuration.yaml" ] || [ -f "$PDATA/configuration.yaml" ]; then
        continue
    fi
    echo "<INFO> Plugin $ORIGDIR found - taking over its Zigbee network"
    if systemctl is-active --quiet $ORIGSERVICE; then
        echo "<INFO> Stopping service $ORIGSERVICE (only one service may use the Zigbee adapter)"
        systemctl stop $ORIGSERVICE
    fi
    if systemctl is-enabled --quiet $ORIGSERVICE 2>/dev/null; then
        systemctl disable $ORIGSERVICE
    fi
    cp -a "$ORIGDATA/." "$PDATA/"
    for f in mqtt.json service.json; do
        if [ -f "$ORIGCONFIG/$f" ]; then
            cp -f "$ORIGCONFIG/$f" "$PCONFIG/$f"
        fi
    done

    if [ "$ORIGDIR" = "zigbee2lox" ]; then
        # The copied bridge extension of Zigbee2Lox must not be loaded next to
        # ours, its control file is rewritten by update-config.php below.
        rm -f "$PDATA/external_extensions/zigbee2lox.mjs" "$PDATA/zigbee2lox.json"
        for f in devices groups info haus; do
            if [ -f "$PDATA/zigbee2lox_$f.json" ]; then
                mv -f "$PDATA/zigbee2lox_$f.json" "$PDATA/zigbee2mqttng_$f.json"
            fi
        done
        # The haus/tuer topics belong to this plugin now - the uninstall of
        # Zigbee2Lox must not delete them.
        rm -f "$ORIGDATA/zigbee2lox_haus.json"
        # Zigbee2Lox fetches its updates from this repository. Without this its
        # auto update would install Zigbee2MqttNG again every night.
        perl -I"$LBHOMEDIR/libs/perllib" -e '
            use LoxBerry::System::PluginDB;
            foreach my $md5 (LoxBerry::System::PluginDB->search(folder => "zigbee2lox")) {
                my $plugin = LoxBerry::System::PluginDB->plugin(md5 => $md5);
                next if (!$plugin);
                $plugin->{autoupdate} = "0";
                $plugin->save();
            }' && echo "<INFO> Automatic updates of Zigbee2Lox disabled" \
            || echo "<WARNING> Could not disable the automatic updates of Zigbee2Lox. Please uninstall Zigbee2Lox."
    fi

    MIGRATED=1
    echo "<WARNING> The plugin $ORIGDIR is still installed. Please uninstall it - an update of it would start its service again and both would fight for the Zigbee adapter."
done

echo "<INFO> Refresh config"
php $PBIN/update-config.php

chown loxberry:loxberry $PDATA/* -R
chown loxberry:loxberry $PCONFIG/* -R

# if we have a new installation we setup the encryption
# https://github.com/romanlum/LoxBerry-Plugin-Zigbee2Mqtt/issues/13
# A taken over network already has its key - generating a new one would cut off every device.
if [ "$ISUPGRADE" -eq "0" ] && [ "$MIGRATED" -eq "0" ]; then
    echo "<INFO> Fresh installation detected - Set encryption key"
    php $PBIN/setup-encryption.php
fi

echo "<INFO> Updating service config"
if [ "$PIVERS" = 'type_0' ] || [ "$PIVERS" = 'type_1' ]; then
    ln -f -s $PCONFIG/zigbee2mqttngNode10.service /etc/systemd/system/$SERVICE.service
else
    ln -f -s $PCONFIG/zigbee2mqttng.service /etc/systemd/system/$SERVICE.service
fi

# Enable auto-start of the service
systemctl daemon-reload
systemctl enable $SERVICE
systemctl start $SERVICE

# Exit with Status 0
exit 0
