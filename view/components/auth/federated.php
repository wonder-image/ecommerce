<?php
$googleUrl = __r('ecommerce.auth.federated', ['provider' => 'google']);
$auth_surface ??= 'login';
if ($google_client_id === '') { return; }
?>
<div class="w-100 mt-6">
    <p class="text-small a-c"><?=e(__t('ecommerce.auth.federated.separator'))?></p>
    <div class="d-grid col-1 gap-3 mt-3 w-100">
        <div id="ecommerce-google-signin" class="d-flex j-content-center w-100"></div>
    </div>
</div>
<script>
(() => {
    const googleContainer = document.getElementById('ecommerce-google-signin');
    const canUseGoogle = () => {
        if (<?=js_e($auth_surface)?> !== 'signup') return true;

        return ['accept_privacy_policy', 'accept_terms_conditions'].every(name => {
            const consent = document.querySelector(`[name="${name}"]`);
            return consent && consent.checked;
        });
    };
    const updateGoogleAvailability = () => {
        const enabled = canUseGoogle();
        googleContainer.style.pointerEvents = enabled ? 'auto' : 'none';
        googleContainer.style.opacity = enabled ? '1' : '.55';
        googleContainer.setAttribute('aria-disabled', enabled ? 'false' : 'true');
    };
    document.addEventListener('change', updateGoogleAvailability);
    updateGoogleAvailability();

    const postToken = token => {
        const form = document.createElement('form');
        form.id = <?=js_e($auth_surface === 'signup' ? 'google_sign_up' : 'google_login')?>;
        form.method = 'post';
        form.action = <?=js_e($googleUrl)?>;
        const values = {
            credential: token,
            csrf_token: <?=js_e($csrf_token)?>,
            auth_surface: <?=js_e($auth_surface)?>
        };
        [
            'accept_privacy_policy',
            'accept_terms_conditions',
            'continue'
        ].forEach(name => {
            const source = document.querySelector(`[name="${name}"]`);
            if (source && (source.type !== 'checkbox' || source.checked)) values[name] = source.value;
        });
        Object.entries(values).forEach(([name, value]) => {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
        });
        document.body.appendChild(form); form.submit();
    };

    const googleScript = document.createElement('script');
    googleScript.src = 'https://accounts.google.com/gsi/client';
    googleScript.async = true;
    googleScript.defer = true;
    googleScript.onload = () => {
        const googleButtonWidth = Math.floor(googleContainer.getBoundingClientRect().width);
        google.accounts.id.initialize({
            client_id: <?=js_e($google_client_id)?>,
            nonce: <?=js_e($oidc_nonce)?>,
            callback: response => postToken(response.credential)
        });
        google.accounts.id.renderButton(
            googleContainer,
            {theme: 'outline', size: 'large', width: googleButtonWidth}
        );
        updateGoogleAvailability();
    };
    document.head.appendChild(googleScript);
})();
</script>
