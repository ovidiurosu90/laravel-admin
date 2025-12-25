import { defineConfig, splitVendorChunkPlugin, loadEnv } from "vite";
import { createHtmlPlugin } from "vite-plugin-html";
import { esbuildCommonjs } from "@originjs/vite-plugin-commonjs";
import { viteCommonjs } from "@originjs/vite-plugin-commonjs";
import legacy from "@vitejs/plugin-legacy";
import vue from "@vitejs/plugin-vue";
import manifestSRI from "vite-plugin-manifest-sri";
import { ViteMinifyPlugin } from "vite-plugin-minify";
import { viteStaticCopy } from "vite-plugin-static-copy";
import viteImagemin from "vite-plugin-imagemin";
import Pages from "vite-plugin-pages";
import generateSitemap from "vite-plugin-pages-sitemap";
import { viteExternalsPlugin } from "vite-plugin-externals";

import laravel from "laravel-vite-plugin";
import path from "path";
import { webUpdateNotice } from "@plugin-web-update-notification/vite";

// import eslint from "vite-plugin-eslint"; // not needed
// import StylelintPlugin from "vite-plugin-stylelint"; // not needed

const externalLibs = [
  // "jquery",
  // "Vue",
];
const externalGlobals = {
  // jquery: "jQuery",
  // vue: "Vue",
};

export default defineConfig(({ mode }) => {
  process.env = { ...process.env, ...loadEnv(mode, process.cwd()) };

  let plugins = [
    laravel({
      input: ["resources/assets/sass/app.scss", "resources/assets/js/app.js"],
      refresh: true,
    }),
    // StylelintPlugin({
    //     fix: true,
    //     quite: false,
    //     lintOnStart: false,
    // }),
    // eslint({
    //     cache: true,
    //     fix: true,
    //     lintOnStart: false,
    //     emitWarning: true,
    //     emitError: true,
    //     failOnWarning: false,
    //     failOnError: true,
    // }),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
      features: {
        prodDevtools: true,
      },
    }),
    ViteMinifyPlugin({
      minifyCSS: true,
      removeComments: true,
    }),
    createHtmlPlugin({
      minify: true,
      entry: "resources/assets/js/app.js",
    }),
    viteCommonjs(),
    // manifestSRI(), // disabled: causing manifest generation error
    // webUpdateNotice disabled: conflicts with manifest generation
    // webUpdateNotice({
    //   logVersion: true,
    //   logHash: true,
    //   checkInterval: 0.5 * 60 * 1000,
    //   notificationProps: {
    //     title: "system update",
    //     description: "System update, please refresh the page",
    //     buttonText: "refresh",
    //   },
    // }),
    ...(mode === "build"
      ? [
          viteStaticCopy({
            targets: [
              {
                src: "node_modules/hideshowpassword/images/wink.svg",
                dest: "../../public/images",
              },
              {
                src: "node_modules/hideshowpassword/images/wink.png",
                dest: "../../public/images",
              },
              // {
              //     src: 'resources/img/favicon/favicon.ico',
              //     dest: '../',
              // },
            ], // end targets
          }),
        ]
      : []),
    viteImagemin({
      gifsicle: {
        optimizationLevel: 7,
        interlaced: false,
      },
      optipng: {
        optimizationLevel: 7,
      },
      mozjpeg: {
        quality: 20,
      },
      pngquant: {
        quality: [0.8, 0.9],
        speed: 4,
      },
      svgo: {
        plugins: [
          {
            name: "removeViewBox",
          },
          {
            name: "removeEmptyAttrs",
            active: false,
          },
        ],
      },
    }), // end viteImagemin
    legacy({
      targets: ["defaults", "not IE 11"],
      polyfills: true,
    }),
    Pages({
      onRoutesGenerated: async (routes) => {
        generateSitemap({
          hostname: process.env.VITE_APP_NAME,
          routes: [...routes],
          readable: true,
          allowRobots: false,
          filename: "sitemap.xml",
        });
      },
    }),
  ]; // end plugins

  plugins.push(viteExternalsPlugin(externalGlobals, { disableInServe: true }));

  const config = {
    plugins: plugins,
    build: {
      ssr: false,
      minify: "esnext",
      reportCompressedSize: true,
      chunkSizeWarningLimit: 1600,
      manifest: true,
      sourcemap: process.env.VITE_APP_ENV == "local" ? true : false,
      rollupOptions: {
        external: externalLibs,
        output: {
          manualChunks(id, { getModuleInfo }) {
            const match = /.*\.strings\.(\w+)\.js/.exec(id);
            if (match) {
              const language = match[1]; // e.g. "en"
              const dependentEntryPoints = [];
              // we use a Set here so we handle each module at most once. This
              // prevents infinite loops in case of circular dependencies
              const idsToHandle = new Set(getModuleInfo(id).dynamicImporters);
              for (const moduleId of idsToHandle) {
                const { isEntry, dynamicImporters, importers } =
                  getModuleInfo(moduleId);
                if (isEntry || dynamicImporters.length > 0)
                  dependentEntryPoints.push(moduleId);
                // The Set iterator is intelligent enough to iterate over elements that
                // are added during iteration
                for (const importerId of importers) idsToHandle.add(importerId);
              }
              // If there is a unique entry, we put it into a chunk based on the entry name
              if (dependentEntryPoints.length === 1) {
                return `${
                  dependentEntryPoints[0].split("/").slice(-1)[0].split(".")[0]
                }.strings.${language}`;
              }
              // For multiple entries, we put it into a "shared" chunk
              if (dependentEntryPoints.length > 1) {
                return `shared.strings.${language}`;
              }
            }
          }, // end manualChunks
          // globals: externalGlobals, // disabled: conflicts with manifest generation in Vite 6
        }, // end output
      }, // end rollupOptions
      modulePreload: {
        polyfill: true,
      },
      commonjsOptions: {
        include: [/node_modules/, /resources\/assets\/js\/vendor/],
      },
    }, // end build
    optimizeDeps: {
      force: true,
      esbuildOptions: {
        plugins: [esbuildCommonjs()],
      },
      exclude: externalLibs,
    },
    sourcemap: true,
    resolve: {
      alias: {
        $: "jQuery",
        tempusDominus: "TempusDominus",
        "~": path.resolve(__dirname, "node_modules"),
        "~bootstrap": path.resolve(__dirname, "node_modules/bootstrap"),
        "~selectize": path.resolve(
          __dirname,
          "node_modules/@selectize/selectize",
        ),
        "~tempus-dominus": path.resolve(
          __dirname,
          "node_modules/@eonasdan/tempus-dominus",
        ),
        "~jquery-tempus-dominus": path.resolve(
          __dirname,
          "resources/assets/js/vendor/jquery-tempus-dominus",
        ),
        "~jquery-mask": path.resolve(
          __dirname,
          "node_modules/jquery-mask-plugin",
        ),
        "~datatables": path.resolve(
          __dirname,
          "node_modules/datatables.net-bs5",
        ),
        "~bootstrap5-toggle": path.resolve(
          __dirname,
          "node_modules/bootstrap5-toggle",
        ),
        "~jquery-bootstrap5-toggle": path.resolve(
          __dirname,
          "resources/assets/js/vendor/jquery-bootstrap5-toggle",
        ),
        "@js": path.resolve(__dirname, "resources/assets/js"),
        "@sass": path.resolve(__dirname, "resources/assets/sass"),
        "~hideshowpassword": path.resolve(
          __dirname,
          "node_modules/hideshowpassword",
        ),
        "~password-strength-meter": path.resolve(
          __dirname,
          "resources/assets/js/vendor/password-strength-meter",
        ),
        "~lightweight-charts": path.resolve(
          __dirname,
          "node_modules/lightweight-charts",
        ),
        // "~font-awesome": path.resolve(__dirname, "node_modules/font-awesome"),
      },
    },
    server: {
      port: 8080,
      hot: true,
    },
  }; // end config

  return config;
});
