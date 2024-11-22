jQuery(document).ready(function ($) {
	$('.cloudflare-url-input').on('change', function () {
		const $wrapper = $(this).closest('.acf-cloudflare-image-wrapper');
		const $preview = $wrapper.find('.cloudflare-image-preview');
		const url = $(this).val();

		if (url) {
			$preview.html(`<img src="${url}" class="preview-image" />`);
		} else {
			$preview.empty();
		}
	});
});
