/** Configuración compartida del login y dashboard. Regenerar con npm run build:css. */
module.exports = {
  "darkMode": "class",
  "theme": {
    "extend": {
      "boxShadow": {
        "modal": "0 25px 60px -15px rgba(6, 29, 73, 0.35)",
        "card": "0 2px 10px rgba(6, 29, 73, 0.04)"
      },
      "colors": {
        "brand": {
          "navy": "#061D49",
          "blue": "#1E3C76",
          "lightBlue": "#EBF1FB",
          "gold": "#F8CF4A",
          "goldHover": "#E5BD3B",
          "goldSoft": "#FEF9E7",
          "grayBg": "#F6F8FC",
          "border": "#E2E8F0"
        },
        "surface-container": "#e9edff",
        "tertiary": "#7d5700",
        "on-secondary": "#ffffff",
        "inverse-surface": "#0b2e67",
        "secondary": "#4b5d8c",
        "surface-container-lowest": "#ffffff",
        "on-tertiary-fixed-variant": "#5f4100",
        "tertiary-fixed": "#ffdea9",
        "error": "#ba1a1a",
        "secondary-fixed": "#dae2ff",
        "primary-container": "#f8cf4a",
        "surface-container-low": "#f2f3ff",
        "surface-bright": "#faf8ff",
        "error-container": "#ffdad6",
        "on-tertiary-container": "#785400",
        "surface-dim": "#cdd9ff",
        "on-secondary-fixed-variant": "#334573",
        "on-tertiary": "#ffffff",
        "primary": "#735c00",
        "on-primary": "#ffffff",
        "on-background": "#001944",
        "on-secondary-container": "#415381",
        "surface-container-high": "#e1e8ff",
        "surface-variant": "#d9e2ff",
        "on-error-container": "#93000a",
        "on-tertiary-fixed": "#271900",
        "on-primary-fixed-variant": "#574500",
        "on-error": "#ffffff",
        "surface-tint": "#735c00",
        "on-surface": "#001944",
        "on-primary-container": "#6f5800",
        "outline-variant": "#d0c6ae",
        "on-secondary-fixed": "#021945",
        "secondary-container": "#b6c8fe",
        "surface-container-highest": "#d9e2ff",
        "primary-fixed": "#ffe087",
        "tertiary-fixed-dim": "#fcbb3b",
        "primary-fixed-dim": "#eac23e",
        "inverse-primary": "#eac23e",
        "on-surface-variant": "#4d4635",
        "tertiary-container": "#ffcb6f",
        "secondary-fixed-dim": "#b3c5fb",
        "inverse-on-surface": "#edf0ff",
        "surface": "#faf8ff",
        "outline": "#7f7662",
        "on-primary-fixed": "#231a00",
        "background": "#faf8ff"
      },
      "borderRadius": {
        "DEFAULT": "0.25rem",
        "lg": "0.5rem",
        "xl": "0.75rem",
        "full": "9999px"
      },
      "spacing": {
        "space-xl": "2.5rem",
        "margin-mobile": "1rem",
        "space-lg": "1.5rem",
        "space-xs": "0.25rem",
        "gutter": "1.5rem",
        "space-sm": "0.5rem",
        "margin": "2rem",
        "gutter-mobile": "1rem",
        "space-md": "1rem"
      },
      "fontFamily": {
        "label-md": [
          "Poppins"
        ],
        "headline-md": [
          "Poppins"
        ],
        "label-lg": [
          "Poppins"
        ],
        "headline-lg": [
          "Poppins"
        ],
        "display-lg": [
          "Poppins"
        ],
        "headline-xl": [
          "Poppins"
        ],
        "body-lg": [
          "Poppins"
        ],
        "label-sm": [
          "Poppins"
        ],
        "body-sm": [
          "Poppins"
        ],
        "title-md": [
          "Poppins"
        ],
        "display-lg-mobile": [
          "Poppins"
        ],
        "headline-sm": [
          "Poppins"
        ],
        "headline-xl-mobile": [
          "Poppins"
        ],
        "body-md": [
          "Poppins"
        ]
      },
      "fontSize": {
        "label-md": [
          "12px",
          {
            "lineHeight": "16px",
            "letterSpacing": "0.02em",
            "fontWeight": "500"
          }
        ],
        "headline-md": [
          "22px",
          {
            "lineHeight": "30px",
            "letterSpacing": "-0.005em",
            "fontWeight": "600"
          }
        ],
        "label-lg": [
          "14px",
          {
            "lineHeight": "20px",
            "letterSpacing": "0.01em",
            "fontWeight": "600"
          }
        ],
        "headline-lg": [
          "28px",
          {
            "lineHeight": "36px",
            "letterSpacing": "-0.01em",
            "fontWeight": "600"
          }
        ],
        "display-lg": [
          "48px",
          {
            "lineHeight": "56px",
            "letterSpacing": "-0.02em",
            "fontWeight": "700"
          }
        ],
        "headline-xl": [
          "36px",
          {
            "lineHeight": "44px",
            "letterSpacing": "-0.015em",
            "fontWeight": "600"
          }
        ],
        "body-lg": [
          "16px",
          {
            "lineHeight": "26px",
            "fontWeight": "400"
          }
        ],
        "label-sm": [
          "10px",
          {
            "lineHeight": "14px",
            "letterSpacing": "0.04em",
            "fontWeight": "600"
          }
        ],
        "body-sm": [
          "12px",
          {
            "lineHeight": "18px",
            "fontWeight": "400"
          }
        ],
        "title-md": [
          "16px",
          {
            "lineHeight": "24px",
            "fontWeight": "500"
          }
        ],
        "display-lg-mobile": [
          "32px",
          {
            "lineHeight": "40px",
            "letterSpacing": "-0.01em",
            "fontWeight": "700"
          }
        ],
        "headline-sm": [
          "18px",
          {
            "lineHeight": "26px",
            "fontWeight": "600"
          }
        ],
        "headline-xl-mobile": [
          "26px",
          {
            "lineHeight": "34px",
            "letterSpacing": "-0.01em",
            "fontWeight": "600"
          }
        ],
        "body-md": [
          "14px",
          {
            "lineHeight": "22px",
            "fontWeight": "400"
          }
        ]
      }
    }
  },
  "content": {
    "relative": true,
    "files": [
      "./public/**/*.php",
      "./src/**/*.php",
      "./public/assets/js/**/*.js"
    ]
  }
};
