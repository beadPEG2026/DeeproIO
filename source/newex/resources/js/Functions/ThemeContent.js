// CMS content carries meaning and structure; the site's palette owns presentation.
// This complements the existing CMS trust/sanitization rules, not an HTML sanitizer.
export function themeContent(value) {
  if (!value || typeof DOMParser === 'undefined') return value || '';
  const document = new DOMParser().parseFromString(String(value), 'text/html');
  document.body.querySelectorAll('[style],[color],[bgcolor]').forEach(element => {
    ['color', 'background', 'background-color', 'background-image', 'border-color', 'text-shadow', 'box-shadow'].forEach(property => element.style.removeProperty(property));
    element.removeAttribute('color');
    element.removeAttribute('bgcolor');
    if (!element.getAttribute('style')) element.removeAttribute('style');
  });
  return document.body.innerHTML;
}
