<template>
    <dl>
        <dd>{{ $t('Relay switching time') }}</dd>
        <dt>
            <span class="input-group">
                <input type="number"
                    step="0.5"
                    min="0.5"
                    max="3600"
                    class="form-control text-center"
                    v-model="param1">
                <span class="input-group-addon">
                    {{ $t('sec.') }}
                </span>
            </span>
        </dt>
    </dl>
</template>

<script>
    import ChannelParamsControllingAnyLock from "./channel-params-controlling-any-lock";
    import ChannelsDropdown from "../../devices/channels-dropdown";

    export default {
        components: {ChannelsDropdown, ChannelParamsControllingAnyLock},
        props: ['channel'],
        computed: {
            param1: {
                set(value) {
                    this.channel.param1 = Math.round(value * 10);
                    this.$emit('change');
                },
                get() {
                    return this.channel.param1 / 10;
                }
            }
        }
    };
</script>
