import tailwindcss from "tailwindcss";
import tailwindcssNesting from "tailwindcss/nesting/index.js";
import postCssNesting from "postcss-nesting";
import autoprefixer from "autoprefixer";
import postcssImport from "postcss-import";

export default {
  plugins: [
    tailwindcss,
    tailwindcssNesting(postCssNesting),
    autoprefixer,
    postcssImport,
  ],
};
